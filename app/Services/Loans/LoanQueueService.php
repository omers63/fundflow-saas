<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Builds the three loan-queue stages:
 *  1. Intake     — pending applications, FIFO with emergencies pinned first.
 *  2. Tier queues — approved/partially disbursed loans stacked per fund tier by queue_position.
 *  3. Process queue — approved / partially disbursed loans with remaining balance, ordered by tier
 *     and queue position. {@see processCoverage()} marks which rows are fundable now vs waiting on pool.
 */
class LoanQueueService
{
    /** @var array<int, array{amount: float, full: bool}>|null */
    private ?array $coverage = null;

    /** @var EloquentCollection<int, FundTier>|null */
    private ?EloquentCollection $activeTiers = null;

    /** @var array<int, EloquentCollection<int, Loan>> */
    private array $queuedLoans = [];

    public function __construct(private LoanQueueProjectionService $projections) {}

    public function projections(): LoanQueueProjectionService
    {
        return $this->projections;
    }

    /**
     * Pending applications in submission order, emergencies first.
     */
    public function intakeQuery(): Builder
    {
        return Loan::query()
            ->with(['member', 'loanTier', 'fundTier'])
            ->select('loans.*')
            ->where('loans.status', 'pending')
            ->orderByDesc('loans.is_emergency')
            ->orderBy('loans.applied_at')
            ->orderBy('loans.id');
    }

    /**
     * All loans awaiting disbursement, in tier + queue-position order.
     */
    public function processQuery(): Builder
    {
        return Loan::query()
            ->with(['member', 'loanTier', 'fundTier'])
            ->select('loans.*')
            ->whereIn('loans.status', ['approved', 'partially_disbursed'])
            ->whereRaw('COALESCE(loans.amount_disbursed, 0) < COALESCE(loans.amount_approved, loans.amount_requested, 0)')
            ->leftJoin('fund_tiers', 'fund_tiers.id', '=', 'loans.fund_tier_id')
            ->orderBy('fund_tiers.tier_number')
            ->orderByRaw('loans.queue_position IS NULL, loans.queue_position')
            ->orderBy('loans.applied_at');
    }

    /**
     * Walk each tier's queue in position order allocating the disbursable pool.
     * A loan is in the process queue when at least a minimal tranche can be paid.
     *
     * @return array<int, array{amount: float, full: bool}> loan id => coverage
     */
    public function processCoverage(): array
    {
        if ($this->coverage !== null) {
            return $this->coverage;
        }

        $coverage = [];
        $globalRemaining = $this->masterFundDisbursableNow();

        foreach ($this->activeTiers() as $tier) {
            $tierPolicyPool = max(0.0, (float) $tier->allocated_amount - $tier->activeLoanExposure());

            foreach ($this->queuedLoansForTier($tier) as $loan) {
                if ($globalRemaining < 0.01 || $tierPolicyPool < 0.01) {
                    break;
                }

                $remaining = $loan->remainingToDisburse();
                if ($remaining < 0.01) {
                    continue;
                }

                $amount = min($remaining, $tierPolicyPool, $globalRemaining);
                $coverage[(int) $loan->id] = [
                    'amount' => round($amount, 2),
                    'full' => $amount + 0.01 >= $remaining,
                ];
                $tierPolicyPool -= $amount;
                $globalRemaining -= $amount;
            }
        }

        return $this->coverage = $coverage;
    }

    /** Master fund on hand — the shared ceiling when tier allocations overlap. */
    public function masterFundDisbursableNow(): float
    {
        return max(0.0, (float) (Account::masterFund()?->balance ?? 0));
    }

    /**
     * Per-tier queue cards: pool figures plus ordered queued loan rows (with coverage
     * and projection) and running (active) loans with repayment progress.
     *
     * @return list<array{
     *     tier: FundTier,
     *     allocated: float,
     *     committed: float,
     *     available: float,
     *     disbursable: float,
     *     loans: list<array{loan: Loan, remaining: float, coverage: array{amount: float, full: bool}|null, projection: array{ready_now: bool, months_min: int|null, months_max: int|null, label: string}}>,
     *     running: list<array{loan: Loan, installments_total: int, installments_paid: int, repay_percent: int, outstanding: float}>
     * }>
     */
    public function tierQueues(): array
    {
        $coverage = $this->processCoverage();

        $cards = [];

        foreach ($this->activeTiers() as $tier) {
            $loans = $this->queuedLoansForTier($tier);

            $cards[] = [
                'tier' => $tier,
                'allocated' => (float) $tier->allocated_amount,
                'committed' => (float) $tier->active_exposure,
                'available' => (float) $tier->available_amount,
                'disbursable' => (float) $tier->disbursable_pool,
                'loans' => $loans->map(fn (Loan $loan): array => [
                    'loan' => $loan,
                    'remaining' => $loan->remainingToDisburse(),
                    'coverage' => $coverage[(int) $loan->id] ?? null,
                    'projection' => $this->projections->projectionFor($loan),
                ])->all(),
                'running' => $this->runningLoansForTier($tier)->map(function (Loan $loan): array {
                    $total = (int) $loan->getAttribute('installments_total');
                    $paid = (int) $loan->getAttribute('installments_paid');

                    return [
                        'loan' => $loan,
                        'installments_total' => $total,
                        'installments_paid' => $paid,
                        'repay_percent' => $total > 0 ? (int) round(($paid / $total) * 100) : 0,
                        'outstanding' => (float) ($loan->getAttribute('outstanding_scheduled') ?? 0),
                    ];
                })->all(),
            ];
        }

        return $cards;
    }

    /**
     * @return array{intake: int, queued: int, queued_demand: float, disbursable: float, process: int, emergency: int, running: int}
     */
    public function kpis(): array
    {
        $queuedDemand = 0.0;
        $queuedCount = 0;

        foreach ($this->activeTiers() as $tier) {
            $queuedDemand += $tier->queuedRemainingExposure();
            $queuedCount += Loan::query()
                ->where('fund_tier_id', $tier->id)
                ->whereIn('status', ['approved', 'partially_disbursed'])
                ->count();
        }

        return [
            'intake' => Loan::query()->needsDecision()->count(),
            'queued' => $queuedCount,
            'queued_demand' => round($queuedDemand, 2),
            'disbursable' => round($this->masterFundDisbursableNow(), 2),
            'process' => count($this->processCoverage()),
            'emergency' => Loan::query()->inQueue()->where('is_emergency', true)->count(),
            'running' => Loan::query()->where('status', 'active')->count(),
        ];
    }

    /**
     * Active loans across all tiers for dashboard / summary views.
     *
     * @return list<array{
     *     id: int,
     *     member_name: string,
     *     member_initials: string,
     *     fund_tier: string|null,
     *     amount_approved: float,
     *     outstanding: float,
     *     installments_total: int,
     *     installments_paid: int,
     *     repay_percent: int,
     *     url: string,
     * }>
     */
    public function runningLoansPreview(int $limit = 5): array
    {
        return Loan::query()
            ->with(['member', 'fundTier'])
            ->withCount([
                'installments as installments_total',
                'installments as installments_paid' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->withSum([
                'installments as outstanding_scheduled' => fn ($query) => $query->whereIn('status', ['pending', 'overdue']),
            ], 'amount')
            ->where('status', 'active')
            ->orderBy('disbursed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Loan $loan): array {
                $member = $loan->member;
                $total = (int) $loan->getAttribute('installments_total');
                $paid = (int) $loan->getAttribute('installments_paid');

                return [
                    'id' => (int) $loan->id,
                    'member_name' => $member?->name ?? '—',
                    'member_initials' => $member ? mb_strtoupper(
                        collect(explode(' ', $member->name))
                            ->filter()
                            ->map(fn (string $w): string => mb_substr($w, 0, 1))
                            ->take(2)
                            ->implode('')
                    ) : '??',
                    'fund_tier' => $loan->fundTier?->label,
                    'amount_approved' => (float) $loan->amount_approved,
                    'outstanding' => (float) ($loan->getAttribute('outstanding_scheduled') ?? 0),
                    'installments_total' => $total,
                    'installments_paid' => $paid,
                    'repay_percent' => $total > 0 ? (int) round(($paid / $total) * 100) : 0,
                    'url' => LoanResource::getUrl('view', ['record' => $loan]),
                ];
            })
            ->all();
    }

    /**
     * Pending and process-queue items for dashboard preview.
     *
     * @return list<array<string, mixed>>
     */
    public function actionPreview(int $limit = 5): array
    {
        $rows = [];

        $intake = Loan::query()
            ->with('member')
            ->where('status', 'pending')
            ->orderByDesc('is_emergency')
            ->orderBy('applied_at')
            ->limit($limit)
            ->get();

        foreach ($intake as $loan) {
            $rows[] = $this->previewRow($loan, 'intake');
            if (count($rows) >= $limit) {
                return $rows;
            }
        }

        $remaining = max(0, $limit - count($rows));
        if ($remaining > 0) {
            $process = $this->processQuery()
                ->with('member')
                ->limit($remaining)
                ->get();

            foreach ($process as $loan) {
                $rows[] = $this->previewRow($loan, 'process');
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewRow(Loan $loan, string $stage): array
    {
        $member = $loan->member;

        return [
            'id' => (int) $loan->id,
            'stage' => $stage,
            'member_name' => $member?->name ?? '—',
            'member_initials' => $member ? mb_strtoupper(
                collect(explode(' ', $member->name))
                    ->filter()
                    ->map(fn (string $w): string => mb_substr($w, 0, 1))
                    ->take(2)
                    ->implode('')
            ) : '??',
            'amount' => (float) ($loan->amount_approved ?? $loan->amount_requested),
            'is_emergency' => (bool) $loan->is_emergency,
            'queue_position' => $loan->queue_position,
            'url' => LoanResource::getUrl(
                $loan->status === 'pending' ? 'edit' : 'view',
                ['record' => $loan],
            ),
        ];
    }

    /**
     * @return EloquentCollection<int, FundTier>
     */
    private function activeTiers(): EloquentCollection
    {
        return $this->activeTiers ??= FundTier::query()
            ->where('is_active', true)
            ->orderBy('tier_number')
            ->get();
    }

    /**
     * Active loans repaying against this tier's pool, oldest disbursement first.
     *
     * @return EloquentCollection<int, Loan>
     */
    private function runningLoansForTier(FundTier $tier): EloquentCollection
    {
        return Loan::query()
            ->with('member')
            ->withCount([
                'installments as installments_total',
                'installments as installments_paid' => fn ($query) => $query->where('status', 'paid'),
            ])
            ->withSum([
                'installments as outstanding_scheduled' => fn ($query) => $query->whereIn('status', ['pending', 'overdue']),
            ], 'amount')
            ->where('fund_tier_id', $tier->id)
            ->where('status', 'active')
            ->orderBy('disbursed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, Loan>
     */
    private function queuedLoansForTier(FundTier $tier): EloquentCollection
    {
        return $this->queuedLoans[$tier->id] ??= Loan::query()
            ->with(['member', 'loanTier'])
            ->where('fund_tier_id', $tier->id)
            ->whereIn('status', ['approved', 'partially_disbursed'])
            ->orderByRaw('queue_position IS NULL, queue_position')
            ->orderBy('applied_at')
            ->orderBy('id')
            ->get();
    }
}
