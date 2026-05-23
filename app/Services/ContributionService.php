<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContributionService
{
    public function __construct(
        public AccountingService $accounting,
        public ContributionCycleService $cycles,
        public LateFeeService $lateFees,
        public ContributionCollectionCycleService $collectionCycle,
    ) {}

    public function getCycleStartDay(): int
    {
        return $this->cycles->cycleStartDay();
    }

    public function getCyclePeriod(?Carbon $date = null): Carbon
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();

        return Carbon::create($year, $month, 1)->startOfMonth();
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function getCycleDateRange(Carbon $period): array
    {
        $month = (int) $period->month;
        $year = (int) $period->year;

        return [
            'start' => $this->cycles->cycleStartAt($month, $year),
            'end' => $this->cycles->cycleDueEndAt($month, $year),
        ];
    }

    public function recordContribution(Member $member, string $period, ?float $amount = null): Contribution
    {
        $due = (float) ($amount ?? $member->monthly_contribution_amount);

        return Contribution::create([
            'member_id' => $member->id,
            'period' => $period,
            'amount' => $due,
            'amount_due' => $due,
            'amount_collected' => 0,
            'status' => 'pending',
            'collection_status' => ContributionCollectionStatus::PENDING,
            'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
        ]);
    }

    public function postContribution(Contribution $contribution): void
    {
        if ($contribution->status === 'posted') {
            return;
        }

        $member = $contribution->member;
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;

        if ($memberCash === null || $memberFund === null) {
            throw new InvalidArgumentException(__('Member accounts are not configured.'));
        }

        $amount = (float) $contribution->amount;
        $lateFee = (float) ($contribution->late_fee_amount ?? 0);
        $totalDebit = $amount + $lateFee;

        if ($contribution->payment_method === Contribution::PAYMENT_METHOD_CASH_ACCOUNT) {
            if ((float) $memberCash->balance < $totalDebit) {
                $contribution->update(['status' => 'failed']);

                throw new InvalidArgumentException(__('Insufficient cash. Required: :amount', [
                    'amount' => number_format($totalDebit, 2),
                ]));
            }
        }

        DB::transaction(function () use ($contribution): void {
            $this->accounting->postContribution($contribution);

            $contribution->update([
                'status' => 'posted',
                'posted_at' => now(),
                'paid_at' => $contribution->paid_at ?? now(),
            ]);
        });

        if (LoanSettings::autoAllocateLoanRepayment()) {
            app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($contribution->member);
        }
    }

    /**
     * Apply (create + post) a cycle contribution for one member.
     *
     * @param  array<string, mixed>  $results
     */
    public function applyForPeriod(Member $member, int $month, int $year, array &$results = []): string
    {
        if (Contribution::activePeriodExists($member->id, $month, $year)) {
            $results['skipped'][] = $member;

            return 'already_contributed';
        }

        if ($member->isExemptFromContributions($month, $year)) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $amount = (float) $member->monthly_contribution_amount;

        if ($amount <= 0) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $period = Contribution::periodDate($month, $year);
        $contribution = Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', $period)
            ->first();

        if ($contribution === null) {
            $contribution = Contribution::create([
                'member_id' => $member->id,
                'period' => $period,
                'amount' => $amount,
                'amount_due' => $amount,
                'amount_collected' => 0,
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'status' => 'pending',
                'collection_status' => ContributionCollectionStatus::PENDING,
                'cycle_open_cash_balance' => $member->getCashBalance(),
            ]);
        }

        $deadline = $this->cycles->deadline($month, $year);

        if (now()->greaterThan($deadline) && $contribution->overdue_since === null) {
            $contribution->update([
                'collection_status' => ContributionCollectionStatus::OVERDUE,
                'overdue_since' => $deadline,
                'is_late' => true,
            ]);
            $this->collectionCycle->applyLateFeeTierForContribution($contribution->fresh());
            $contribution = $contribution->fresh();
        }

        try {
            $outcome = $this->collectionCycle->attemptCollection($contribution);
        } catch (UniqueConstraintViolationException|ValidationException) {
            $results['skipped'][] = $member;

            return 'already_contributed';
        } catch (InvalidArgumentException $exception) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->getCashBalance(),
                'required' => (float) ($contribution->amount_due ?? $amount),
            ];

            return 'insufficient';
        }

        if ($outcome === 'collected' || $outcome === 'partial') {
            $results['applied'][] = $member;

            return $outcome === 'partial' ? 'partial' : 'applied';
        }

        if ($outcome === 'insufficient') {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->getCashBalance(),
                'required' => max(0, (float) ($contribution->amount_due ?? 0) - (float) ($contribution->amount_collected ?? 0)),
            ];

            return 'insufficient';
        }

        return 'skipped';
    }

    /**
     * @return array{applied: Collection, insufficient: Collection, skipped: Collection}
     */
    public function applyContributionsForPeriod(int $month, int $year): array
    {
        $results = [
            'applied' => collect(),
            'insufficient' => collect(),
            'skipped' => collect(),
        ];

        Member::active()->with('user')->each(function (Member $member) use ($month, $year, &$results): void {
            $this->applyForPeriod($member, $month, $year, $results);
        });

        return $results;
    }

    public function generateMonthlyContributions(int|string|null $month = null, ?int $year = null): int
    {
        if (is_string($month)) {
            $date = Carbon::parse($month);
            $month = (int) $date->month;
            $year = (int) $date->year;
        }

        if ($month === null || $year === null) {
            [$month, $year] = $this->cycles->currentOpenPeriod();
        }

        $period = Contribution::periodDate($month, $year);
        $count = 0;

        Member::active()->each(function (Member $member) use ($period, &$count): void {
            $exists = Contribution::where('member_id', $member->id)
                ->where('period', $period)
                ->exists();

            if (! $exists && (float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions((int) date('m', strtotime($period)), (int) date('Y', strtotime($period)))) {
                $this->recordContribution($member, $period);
                $count++;
            }
        });

        return $count;
    }

    public function periodLabel(int $month, int $year): string
    {
        return $this->cycles->periodLabel($month, $year);
    }
}
