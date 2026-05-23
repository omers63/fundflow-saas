<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Services\FundAuditLogService;
use App\Services\MemberDelinquencyEvaluator;
use App\Support\InstallmentCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Marks overdue installments, syncs member delinquency, and manages guarantor liability transfer.
 */
class LoanDelinquencyService
{
    private const CONTRIBUTION_ARREARS_LOOKBACK_MONTHS = 24;

    public function __construct(
        protected ContributionCycleService $cycles,
        protected LateFeeService $lateFees,
        protected LoanDefaultService $defaults,
        protected MemberDelinquencyEvaluator $delinquencyEvaluator,
    ) {}

    /**
     * Daily pipeline: mark overdue → sync member status → process defaults (warnings / guarantor debits).
     *
     * @return array{marked_overdue: int, marked_delinquent: int, restored_active: int, warned: int, debited_from_guarantor: int}
     */
    public function runDailyMaintenance(): array
    {
        $markedOverdue = $this->markOverdueInstallments();
        $memberSync = $this->syncMemberDelinquencyStatus();
        $defaults = $this->defaults->processDefaults();
        $transferred = $this->evaluateMissedEmiTransfers();

        return [
            'marked_overdue' => $markedOverdue,
            'marked_delinquent' => $memberSync['marked_delinquent'],
            'restored_active' => $memberSync['restored_active'],
            'warned' => $defaults['warned'] ?? 0,
            'debited_from_guarantor' => $defaults['debited_from_guarantor'] ?? 0,
            'transferred_to_guarantor' => $transferred,
        ];
    }

    /**
     * Auto-transfer loans to guarantor when missed EMI threshold is reached (spec Y).
     */
    public function evaluateMissedEmiTransfers(): int
    {
        $threshold = Setting::loanGuarantorTransferMissedThreshold();
        $transferred = 0;

        Loan::query()
            ->where('status', 'active')
            ->whereNotNull('guarantor_member_id')
            ->whereNull('transferred_to_guarantor_at')
            ->where('late_repayment_count', '>=', $threshold)
            ->whereHas('installments', fn ($q) => $q->where('status', 'overdue'))
            ->each(function (Loan $loan) use (&$transferred): void {
                try {
                    app(LoanGuarantorTransferService::class)->transferToGuarantor($loan);
                    $transferred++;
                } catch (InvalidArgumentException) {
                    // Skip loans that fail validation (already transferred, no guarantor, etc.)
                }
            });

        return $transferred;
    }

    public function reinstateSuspendedBorrower(Loan $loan): void
    {
        $originalId = $loan->original_borrower_member_id;

        if ($originalId === null) {
            throw new InvalidArgumentException(__('This loan has no suspended original borrower on record.'));
        }

        $borrower = Member::query()->find($originalId);

        if ($borrower === null) {
            throw new InvalidArgumentException(__('Original borrower member not found.'));
        }

        if ($borrower->status !== 'suspended') {
            throw new InvalidArgumentException(__('Borrower is not suspended.'));
        }

        if ($loan->installments()->whereIn('status', ['pending', 'overdue'])->exists()) {
            throw new InvalidArgumentException(__('Clear guarantor loan installments before reinstating the borrower.'));
        }

        $borrower->update(['status' => 'active']);
        app(FundAuditLogService::class)->log('BORROWER_REINSTATED', 'loan', $loan, $borrower);
    }

    /**
     * @return array{
     *     overdue_installments: int,
     *     contribution_arrears_periods: int,
     *     contribution_arrears_members: int,
     *     delinquent_members: int,
     *     guarantor_at_risk: int,
     *     guarantor_transferred: int
     * }
     */
    public function digestCounts(): array
    {
        $records = $this->contributionArrearsTableRecords();

        return [
            'overdue_installments' => (int) LoanInstallment::query()
                ->where('status', 'overdue')
                ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
                ->count(),
            'contribution_arrears_periods' => $records->count(),
            'contribution_arrears_members' => $records->pluck('member_id')->unique()->count(),
            'delinquent_members' => (int) Member::query()->where('status', 'delinquent')->count(),
            'guarantor_at_risk' => $this->loansAtGuarantorRiskCount(),
            'guarantor_transferred' => (int) Loan::query()
                ->where('status', 'active')
                ->whereNotNull('guarantor_liability_transferred_at')
                ->count(),
        ];
    }

    /**
     * @return array{
     *     has_arrears: bool,
     *     is_delinquent: bool,
     *     overdue_installment_count: int,
     *     unpaid_contribution_periods: list<string>,
     *     unpaid_contribution_details: list<array{period_label: string, contribution_status: string, late_fee: float}>
     * }
     */
    public function memberArrearsSummary(Member $member): array
    {
        $periods = $this->unpaidContributionPeriods($member);
        $overdueCount = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id)->where('status', 'active'))
            ->count();

        return [
            'has_arrears' => $overdueCount > 0 || $periods !== [],
            'is_delinquent' => $member->status === 'delinquent',
            'overdue_installment_count' => $overdueCount,
            'unpaid_contribution_periods' => array_column($periods, 'period_label'),
            'unpaid_contribution_details' => $periods,
        ];
    }

    public function contributionStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('Pending'),
            'failed' => __('Failed'),
            'missing' => __('Missing'),
            default => ucfirst($status),
        };
    }

    public function contributionStatusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'failed' => 'danger',
            'missing' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Pending installments past their cycle deadline become overdue.
     */
    public function markOverdueInstallments(): int
    {
        $marked = 0;

        LoanInstallment::query()
            ->where('status', 'pending')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->with('loan')
            ->each(function (LoanInstallment $installment) use (&$marked): void {
                if (! $this->installmentIsPastDeadline($installment)) {
                    return;
                }

                $due = $installment->due_date;
                $deadline = $this->cycles->deadline((int) $due->month, (int) $due->year);
                $days = $this->lateFees->daysPastDue($deadline, now());
                $feeAmt = $this->lateFees->repaymentLateFeeForDays($days);

                $installment->update([
                    'status' => 'overdue',
                    'is_late' => true,
                    'late_fee_amount' => $feeAmt > 0.00001 ? $feeAmt : 0,
                    'collection_status' => InstallmentCollectionStatus::OVERDUE,
                    'overdue_since' => $deadline,
                ]);

                $marked++;
            });

        return $marked;
    }

    /**
     * @return array{marked_delinquent: int, restored_active: int}
     */
    public function syncMemberDelinquencyStatus(): array
    {
        $markedDelinquent = 0;
        $restoredActive = 0;

        Member::query()
            ->whereIn('status', ['active', 'delinquent'])
            ->each(function (Member $member) use (&$markedDelinquent, &$restoredActive): void {
                $breach = $this->memberBreachesDelinquencyPolicy($member);

                if ($member->status === 'active' && $breach) {
                    $member->update(['status' => 'delinquent']);
                    $markedDelinquent++;

                    return;
                }

                if ($member->status === 'delinquent' && ! $breach) {
                    $member->update(['status' => 'active']);
                    $restoredActive++;
                }
            });

        return [
            'marked_delinquent' => $markedDelinquent,
            'restored_active' => $restoredActive,
        ];
    }

    /**
     * @return array{marked_delinquent: int, restored_active: int}
     */
    public function syncMemberDelinquencyStatusForMember(Member $member): array
    {
        $member->refresh();
        $breach = $this->memberBreachesDelinquencyPolicy($member);
        $result = ['marked_delinquent' => 0, 'restored_active' => 0];

        if ($member->status === 'active' && $breach) {
            $member->update(['status' => 'delinquent']);
            $result['marked_delinquent'] = 1;
        } elseif ($member->status === 'delinquent' && ! $breach) {
            $member->update(['status' => 'active']);
            $result['restored_active'] = 1;
        }

        return $result;
    }

    public function memberBreachesDelinquencyPolicy(Member $member): bool
    {
        $stats = $this->delinquencyEvaluator->evaluate($member);

        return $this->delinquencyEvaluator->shouldSuspend(
            $stats['trailing_consecutive'],
            $stats['rolling_total'],
        );
    }

    public function markMemberDelinquent(Member $member): void
    {
        if ($member->status === 'delinquent') {
            throw new InvalidArgumentException(__('Member is already delinquent.'));
        }

        if (! in_array($member->status, ['active', 'delinquent'], true)) {
            throw new InvalidArgumentException(__('Only active members can be marked delinquent.'));
        }

        $member->update(['status' => 'delinquent']);
    }

    public function restoreMemberActive(Member $member, bool $force = false): void
    {
        if ($member->status !== 'delinquent') {
            throw new InvalidArgumentException(__('Member is not delinquent.'));
        }

        if (! $force && $this->memberHasArrears($member)) {
            throw new InvalidArgumentException(__('Clear outstanding installments and contribution arrears before restoring active status, or use force restore.'));
        }

        $member->update(['status' => 'active']);
    }

    public function memberHasArrears(Member $member): bool
    {
        return $this->memberHasOverdueInstallments($member)
            || $this->memberHasContributionArrears($member);
    }

    public function memberHasOverdueInstallments(Member $member): bool
    {
        return LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas(
                'loan',
                fn ($q) => $q->where('member_id', $member->id)->where('status', 'active')
            )
            ->exists();
    }

    public function memberHasContributionArrears(Member $member): bool
    {
        return $this->unpaidContributionPeriods($member) !== [];
    }

    /**
     * @return list<int>
     */
    public function contributionArrearsMemberIds(): array
    {
        return Member::query()
            ->whereIn('status', ['active', 'delinquent'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Member $member): bool => $this->memberHasContributionArrears($member))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     month: int,
     *     year: int,
     *     period_label: string,
     *     contribution_status: string,
     *     late_fee: float,
     *     record_key: string
     * }>
     */
    public function unpaidContributionPeriods(Member $member): array
    {
        if (! $this->memberCanOweContributions($member)) {
            return [];
        }

        $periods = [];
        [$curM, $curY] = $this->cycles->currentOpenPeriod();
        $cursor = Carbon::create($curY, $curM, 1)->startOfMonth();

        for ($i = 0; $i < self::CONTRIBUTION_ARREARS_LOOKBACK_MONTHS; $i++) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if (now()->lte($this->cycles->deadline($month, $year))) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            if (! $this->memberLiableForContributionPeriod($member, $month, $year)) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            $contribution = Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod($month, $year)
                ->first();

            if ($contribution?->status === 'posted') {
                $cursor->subMonthNoOverflow();

                continue;
            }

            $deadline = $this->cycles->deadline($month, $year);
            $days = $this->lateFees->daysPastDue($deadline, now());

            $periods[] = [
                'month' => $month,
                'year' => $year,
                'period_label' => $this->cycles->periodLabel($month, $year),
                'contribution_status' => $contribution?->status ?? 'missing',
                'late_fee' => $this->lateFees->contributionLateFeeForDays($days),
                'record_key' => "{$member->id}-{$year}-".sprintf('%02d', $month),
            ];

            $cursor->subMonthNoOverflow();
        }

        return $periods;
    }

    /**
     * One table row per unpaid period (Filament array records).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function contributionArrearsTableRecords(?int $memberId = null): Collection
    {
        $rows = collect();

        $membersQuery = Member::query()
            ->whereIn('status', ['active', 'delinquent'])
            ->orderBy('name');

        if ($memberId !== null) {
            $membersQuery->whereKey($memberId);
        }

        $membersQuery
            ->get()
            ->each(function (Member $member) use ($rows): void {
                foreach ($this->unpaidContributionPeriods($member) as $period) {
                    $rows->push([
                        '__key' => $period['record_key'],
                        'member_id' => $member->id,
                        'member_name' => $member->name,
                        'member_number' => $member->member_number,
                        'member_status' => $member->status,
                        'monthly_contribution_amount' => (float) $member->monthly_contribution_amount,
                        'period_label' => $period['period_label'],
                        'month' => $period['month'],
                        'year' => $period['year'],
                        'contribution_status' => $period['contribution_status'],
                        'late_fee' => $period['late_fee'],
                    ]);
                }
            });

        return $rows->sortBy([
            ['member_name', 'asc'],
            ['year', 'asc'],
            ['month', 'asc'],
        ])->values();
    }

    /**
     * Apply Filament table search/sort for contribution arrears array records.
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, array<string, mixed>>
     */
    public function filterContributionArrearsRecords(
        Collection $records,
        ?string $search = null,
        ?string $sortColumn = null,
        ?string $sortDirection = null,
        ?int $memberId = null,
    ): Collection {
        if ($memberId !== null) {
            $records = $records->where('member_id', $memberId);
        }

        if (filled($search)) {
            $needle = mb_strtolower($search);

            $records = $records->filter(function (array $record) use ($needle): bool {
                return str_contains(mb_strtolower((string) $record['member_name']), $needle)
                    || str_contains(mb_strtolower((string) $record['member_number']), $needle)
                    || str_contains(mb_strtolower((string) $record['period_label']), $needle);
            });
        }

        $column = $sortColumn ?: 'year';
        $descending = ($sortDirection ?: 'desc') === 'desc';

        $sorted = $records->sort(function (array $a, array $b) use ($column, $descending): int {
            $result = match ($column) {
                'period_label', 'year' => ($a['year'] <=> $b['year']) ?: ($a['month'] <=> $b['month']),
                'month' => ($a['month'] <=> $b['month']) ?: ($a['year'] <=> $b['year']),
                'monthly_contribution_amount', 'late_fee' => (float) ($a[$column] ?? 0) <=> (float) ($b[$column] ?? 0),
                default => (string) ($a[$column] ?? '') <=> (string) ($b[$column] ?? ''),
            };

            return $descending ? -$result : $result;
        });

        return $sorted->values();
    }

    public function transferGuarantorLiability(Loan $loan): void
    {
        app(LoanGuarantorTransferService::class)->transferToGuarantor($loan);
    }

    public function restoreBorrowerLiability(Loan $loan): void
    {
        if ($loan->guarantor_liability_transferred_at === null) {
            throw new InvalidArgumentException(__('Guarantor liability has not been transferred for this loan.'));
        }

        $loan->update(['guarantor_liability_transferred_at' => null]);
    }

    public function installmentIsPastDeadline(LoanInstallment $installment): bool
    {
        $due = $installment->due_date;

        return now()->greaterThan(
            $this->cycles->deadline((int) $due->month, (int) $due->year)
        );
    }

    /**
     * @return Collection<int, array{member: Member, periods: list<string>}>
     */
    public function contributionArrearsRows(): Collection
    {
        return Member::query()
            ->whereIn('id', $this->contributionArrearsMemberIds() ?: [0])
            ->orderBy('name')
            ->get()
            ->map(fn (Member $member): array => [
                'member' => $member,
                'periods' => array_column($this->unpaidContributionPeriods($member), 'period_label'),
            ])
            ->values();
    }

    /**
     * @return list<string>
     */
    public function unpaidContributionPeriodLabels(Member $member): array
    {
        return array_column($this->unpaidContributionPeriods($member), 'period_label');
    }

    public function loansAtGuarantorRiskCount(): int
    {
        $grace = Setting::loanDefaultGraceCycles();

        return Loan::query()
            ->where('status', 'active')
            ->whereNotNull('guarantor_member_id')
            ->whereNull('guarantor_liability_transferred_at')
            ->whereHas('installments', fn ($q) => $q->where('status', 'overdue'))
            ->where('late_repayment_count', '>=', $grace)
            ->count();
    }

    private function memberCanOweContributions(Member $member): bool
    {
        return ! $member->isExemptFromContributions()
            && (float) $member->monthly_contribution_amount > 0;
    }

    private function memberLiableForContributionPeriod(Member $member, int $month, int $year): bool
    {
        if (! $this->memberCanOweContributions($member)) {
            return false;
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $joinedStart = $member->contributionLiabilityStartMonth();

        if ($joinedStart === null) {
            return true;
        }

        return $periodStart->greaterThanOrEqualTo($joinedStart);
    }
}
