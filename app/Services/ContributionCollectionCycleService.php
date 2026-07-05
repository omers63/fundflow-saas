<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientMemberCashForCollectionException;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanInstallmentCollectionService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Five-phase collection cycle per docs/collection_cycle_workflow.md.
 */
class ContributionCollectionCycleService
{
    public function __construct(
        protected AccountingService $accounting,
        protected ContributionCycleService $cycles,
        protected LateFeeService $lateFees,
    ) {}

    public function initializeOpenPeriod(int $month, int $year): int
    {
        $period = Contribution::periodDate($month, $year);
        $created = 0;

        Member::active()->each(function (Member $member) use ($period, $month, $year, &$created): void {
            if ($member->isExemptFromContributions($month, $year)) {
                return;
            }

            $amountDue = (float) $member->monthly_contribution_amount;

            if ($amountDue <= 0) {
                return;
            }

            if (Contribution::memberPeriodRecordExists($member->id, $month, $year)) {
                return;
            }

            try {
                Contribution::create([
                    'member_id' => $member->id,
                    'period' => $period,
                    'amount' => $amountDue,
                    'amount_due' => $amountDue,
                    'amount_collected' => 0,
                    'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                    'status' => 'pending',
                    'collection_status' => ContributionCollectionStatus::PENDING,
                    'cycle_open_cash_balance' => $member->getCashBalance(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return;
            }

            $created++;
        });

        return $created;
    }

    /**
     * Attempt to collect an open contribution for the period (full or partial debit).
     */
    public function attemptCollectionForMember(Member $member, int $month, int $year): string
    {
        if ($member->isExemptFromContributions($month, $year)) {
            return 'exempt';
        }

        $contribution = $this->findOpenContribution($member, $month, $year);

        if ($contribution === null) {
            return 'none';
        }

        return $this->attemptCollection($contribution);
    }

    public function attemptCollection(Contribution $contribution, bool $manualApply = false): string
    {
        if ($contribution->status === 'posted') {
            return 'collected';
        }

        if ($contribution->collection_status === ContributionCollectionStatus::COLLECTED) {
            $this->markCollected($contribution);
            $this->afterContributionPosted($contribution);

            return 'collected';
        }

        $contribution->loadMissing('member');
        $member = $contribution->member;

        if (
            ! $manualApply
            && $contribution->period !== null
            && ! $this->memberPeriodEligibleForAutoCollection(
                $member,
                (int) $contribution->period->month,
                (int) $contribution->period->year,
            )
        ) {
            return 'ineligible';
        }
        $amountDue = (float) ($contribution->amount_due ?? $contribution->amount);
        $collected = (float) ($contribution->amount_collected ?? 0);
        $shortfall = max(0.0, $amountDue - $collected);
        $lateFeeAssessed = (float) ($contribution->late_fee_amount ?? 0);
        $lateFeeDue = max(0.0, $lateFeeAssessed - $this->accounting->contributionLateFeeCollectedAmount($contribution));
        $required = $shortfall + $lateFeeDue;

        if ($required <= 0.00001) {
            $this->markCollected($contribution);

            return 'collected';
        }

        $member->unsetRelation('accounts');
        $cashBalance = $member->getCashBalance();

        if ($cashBalance < 0.00001) {
            return 'insufficient';
        }

        $debitAmount = min($cashBalance, $required);

        if ($debitAmount < $required - 0.00001) {
            $contribution->update(['collection_status' => ContributionCollectionStatus::SETTLING]);

            return $this->postPartialCollection($contribution, $debitAmount, $lateFeeDue, $shortfall);
        }

        $contribution->update(['collection_status' => ContributionCollectionStatus::SETTLING]);

        try {
            return $this->postFullCollection($contribution, $shortfall, $lateFeeDue);
        } catch (InsufficientMemberCashForCollectionException) {
            $member->unsetRelation('accounts');
            $available = $member->getCashBalance();

            if ($available < 0.00001) {
                return 'insufficient';
            }

            return $this->postPartialCollection($contribution, $available, $lateFeeDue, $shortfall);
        }
    }

    /**
     * Re-evaluate open contributions and loan installments when member cash increases
     * (deposit accepted, admin credit, bank post, parent allocation, etc.).
     *
     * Overdue contribution periods are settled oldest first. In-window cycles that
     * are not yet past deadline are not auto-collected on cash deposits (including
     * import cut-off balances). For household parents, each overdue cycle is processed
     * oldest first: allocate to dependents, settle the parent's contribution and loan
     * installments due that cycle, then settle each dependent's contributions (oldest
     * arrears through that cycle) and loan installments due that cycle. Later cycles still
     * run when earlier cycles were only partly funded (allocation and settlement use
     * whatever parent cash remains).
     */
    public function onMemberCashIncreased(Member $member): void
    {
        $member = $member->fresh() ?? $member;
        $member->unsetRelation('accounts');
        $activeDependents = $member->dependents()->where('status', 'active')->orderBy('member_number')->get();

        $hasActiveDependents = $member->parent_member_id === null && $activeDependents->isNotEmpty();

        if ($hasActiveDependents) {
            $this->settleHouseholdContributions($member, $activeDependents);
            $this->settleHouseholdLoanRepayments($member, $activeDependents);

            return;
        }

        $this->settleDirectMemberCash($member);
    }

    /**
     * Settle a member's own contributions and loan repayments after cash is credited directly
     * to their account (portal deposit, admin credit, bank post, etc.).
     */
    protected function settleDirectMemberCash(Member $member): void
    {
        foreach ($this->orderedCollectiblePeriodsForAutoCollection($member) as [$month, $year]) {
            if (! $this->settleSingleContributionPeriod($member, $month, $year)) {
                break;
            }
        }

        $this->settleMemberLoanRepayments($member);
    }

    /**
     * Settle a member's contributions oldest-first through the given period (inclusive).
     */
    protected function settleMemberCashThroughPeriod(Member $member, int $throughMonth, int $throughYear): void
    {
        $throughKey = sprintf('%04d-%02d', $throughYear, $throughMonth);

        foreach ($this->orderedCollectiblePeriodsForAutoCollection($member) as [$month, $year]) {
            if (sprintf('%04d-%02d', $year, $month) > $throughKey) {
                break;
            }

            if (! $this->settleSingleContributionPeriod($member, $month, $year)) {
                break;
            }
        }
    }

    /**
     * Past-due and in-progress contribution periods eligible for auto-collection (oldest first).
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function orderedCollectiblePeriodsForAutoCollection(Member $member): array
    {
        $periods = [];

        foreach ($this->collectibleArrearPeriodsOldestFirst($member) as [$month, $year]) {
            $periods[sprintf('%04d-%02d', $year, $month)] = [$month, $year];
        }

        foreach ($this->openContributionsOrdered($member) as $contribution) {
            $period = $contribution->period;

            if ($period === null) {
                continue;
            }

            $month = (int) $period->month;
            $year = (int) $period->year;
            $key = $period->format('Y-m');

            if (! $this->memberPeriodEligibleForAutoCollection($member, $month, $year)) {
                continue;
            }

            if ($contribution->collection_status === ContributionCollectionStatus::PARTIALLY_PENDING) {
                $periods[$key] = [$month, $year];

                continue;
            }

            if ($this->cycles->isLate($month, $year)) {
                $periods[$key] = [$month, $year];
            }
        }

        ksort($periods);

        return array_values($periods);
    }

    /**
     * Collect one cycle in full before callers advance to the next period.
     */
    protected function settleSingleContributionPeriod(Member $member, int $month, int $year): bool
    {
        if ($member->isExemptFromContributions($month, $year)) {
            return true;
        }

        if (
            $this->findOpenContribution($member, $month, $year) === null
            && $this->contributionRecordMissingForPeriod($member, $month, $year)
            && $this->memberPeriodEligibleForAutoCollection($member, $month, $year)
        ) {
            $this->applyMemberContributionForPeriod($member, $month, $year, createIfMissing: true);
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $member->unsetRelation('accounts');

            if ($member->getCashBalance() < 0.00001) {
                return false;
            }

            $open = $this->findOpenContribution($member, $month, $year);

            if ($open === null) {
                return true;
            }

            $this->syncContributionLateFeesBeforeCollection($open);
            $result = $this->attemptCollection($open->fresh());

            if ($result === 'collected') {
                return true;
            }

            if (in_array($result, ['insufficient', 'partial'], true)) {
                return false;
            }
        }

        return $this->findOpenContribution($member, $month, $year) === null;
    }

    /**
     * Unpaid periods past the cycle deadline (including rows not yet created), oldest first.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function collectibleArrearPeriodsOldestFirst(Member $member): array
    {
        $periods = [];

        foreach (app(LoanDelinquencyService::class)->unpaidContributionPeriods($member) as $row) {
            $periods[sprintf('%04d-%02d', $row['year'], $row['month'])] = [$row['month'], $row['year']];
        }

        ksort($periods);

        return array_values($periods);
    }

    /**
     * Pending contribution periods for a member, oldest first (excludes unposted lookback cycles).
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function openContributionPeriodsForMember(Member $member): array
    {
        $periods = [];

        foreach ($this->openContributionsOrdered($member) as $contribution) {
            $period = $contribution->period;

            if ($period === null) {
                continue;
            }

            $periods[$period->format('Y-m')] = [(int) $period->month, (int) $period->year];
        }

        ksort($periods);

        return array_values($periods);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    protected function outstandingPeriodsForHousehold(Member $parent, SupportCollection $dependents): array
    {
        $periods = [];

        $householdMembers = collect([$parent])
            ->merge($dependents);

        foreach ($householdMembers as $member) {
            foreach ($this->orderedCollectiblePeriodsForAutoCollection($member) as [$month, $year]) {
                $periods[sprintf('%04d-%02d', $year, $month)] = [$month, $year];
            }
        }

        ksort($periods);

        return array_values($periods);
    }

    /**
     * Manual batch apply for one period: allocate parent cash to dependents, then collect contributions.
     *
     * @param  array{applied: SupportCollection, insufficient: SupportCollection, skipped: SupportCollection}  $results
     */
    public function applyHouseholdContributionsForPeriod(
        Member $parent,
        SupportCollection $dependents,
        int $month,
        int $year,
        array &$results,
    ): void {
        $parent = $parent->fresh() ?? $parent;
        $parent->unsetRelation('accounts');

        if ($dependents->isNotEmpty() && $parent->getCashBalance() >= 0.00001) {
            $this->cycles->applyDependentAllocationForParentForPeriod($parent, $month, $year);
        }

        $parent = $parent->fresh() ?? $parent;
        $parent->unsetRelation('accounts');

        app(ContributionService::class)->applyForPeriod($parent, $month, $year, $results);

        foreach ($dependents as $dependent) {
            $dependent = $dependent->fresh() ?? $dependent;
            $dependent->unsetRelation('accounts');

            app(ContributionService::class)->applyForPeriod($dependent, $month, $year, $results);
        }
    }

    protected function settleHouseholdContributions(Member $parent, SupportCollection $dependents): void
    {
        $householdPeriods = $this->outstandingPeriodsForHousehold($parent, $dependents);

        foreach ($householdPeriods as [$month, $year]) {
            $parent = $parent->fresh() ?? $parent;
            $parent->unsetRelation('accounts');

            if ($parent->getCashBalance() < 0.00001) {
                break;
            }

            $allocatedDependentIds = [];

            if ($dependents->isNotEmpty()) {
                $allocation = $this->cycles->applyDependentAllocationForParentForPeriod($parent, $month, $year);
                $allocatedDependentIds = $allocation['allocated_dependent_ids'] ?? [];
            }

            $parent = $parent->fresh() ?? $parent;
            $parent->unsetRelation('accounts');

            $this->settleSingleContributionPeriod($parent, $month, $year);

            $this->settleMemberLoanRepaymentsForPeriod($parent, $month, $year);

            foreach ($dependents as $dependent) {
                $dependent = $dependent->fresh() ?? $dependent;
                $dependent->unsetRelation('accounts');

                if (
                    in_array($dependent->id, $allocatedDependentIds, true)
                    || $dependent->getCashBalance() >= 0.00001
                ) {
                    $this->settleMemberCashThroughPeriod($dependent, $month, $year);
                }

                $this->settleMemberLoanRepaymentsForPeriod($dependent, $month, $year);
            }
        }
    }

    protected function settleHouseholdLoanRepayments(Member $parent, SupportCollection $dependents): void
    {
        $this->settleMemberLoanRepayments($parent);

        foreach ($dependents as $dependent) {
            $this->settleMemberLoanRepayments($dependent);
        }
    }

    protected function settleMemberLoanRepayments(Member $member): void
    {
        app(LoanInstallmentCollectionService::class)->onMemberCashIncreased($member);
    }

    protected function settleMemberLoanRepaymentsForPeriod(Member $member, int $month, int $year): void
    {
        app(LoanInstallmentCollectionService::class)->onMemberCashIncreasedForPeriod($member, $month, $year);
    }

    protected function applyMemberContributionForPeriod(
        Member $member,
        int $month,
        int $year,
        bool $createIfMissing = false,
    ): void {
        if ($member->isExemptFromContributions($month, $year)) {
            return;
        }

        $open = $this->findOpenContribution($member, $month, $year);

        if ($open !== null) {
            $this->syncContributionLateFeesBeforeCollection($open);
            $this->attemptCollection($open->fresh());

            return;
        }

        [$currentMonth, $currentYear] = $this->cycles->currentOpenPeriod();

        $canCreate = $createIfMissing || ($month === $currentMonth && $year === $currentYear);

        if ($canCreate && $this->cycles->memberCanApplyContributionForPeriod($member, $month, $year)) {
            app(ContributionService::class)->applyForPeriod($member, $month, $year);
        }
    }

    public function syncContributionLateFeesBeforeCollection(Contribution $contribution): void
    {
        if (! $this->contributionEligibleForArrearLateFees($contribution)) {
            return;
        }

        $this->ensureLateFeesCurrent($contribution);
    }

    protected function settleMemberContributionsInPeriodOrder(Member $member): void
    {
        for ($pass = 0; $pass < 32; $pass++) {
            $member->unsetRelation('accounts');

            if ($member->getCashBalance() < 0.00001) {
                break;
            }

            $progress = false;

            foreach ($this->openContributionsOrdered($member) as $contribution) {
                $contribution = $contribution->fresh();

                if ($contribution === null) {
                    continue;
                }

                $this->ensureLateFeesCurrent($contribution);
                $result = $this->attemptCollection($contribution->fresh());

                if (in_array($result, ['collected', 'partial'], true)) {
                    $progress = true;
                }
            }

            if (! $progress) {
                break;
            }
        }
    }

    /**
     * @return Collection<int, Contribution>
     */
    protected function openContributionsOrdered(Member $member): Collection
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->orderBy('period')
            ->get();
    }

    protected function ensureLateFeesCurrent(Contribution $contribution): void
    {
        if ($contribution->overdue_since === null) {
            return;
        }

        $this->applyLateFeeTierForContribution($contribution->fresh());
    }

    public function closeCollectionWindow(int $month, int $year): int
    {
        $period = Contribution::periodDate($month, $year);
        $closedAt = $this->cycles->cycleDueEndAt($month, $year);
        $flagged = 0;

        Contribution::query()
            ->where('period', $period)
            ->where('status', 'pending')
            ->whereIn('collection_status', [
                ContributionCollectionStatus::PENDING,
                ContributionCollectionStatus::PARTIALLY_PENDING,
                ContributionCollectionStatus::SETTLING,
            ])
            ->each(function (Contribution $contribution) use ($closedAt, &$flagged): void {
                $contribution->update([
                    'collection_status' => ContributionCollectionStatus::OVERDUE,
                    'overdue_since' => $closedAt,
                    'is_late' => true,
                ]);
                $flagged++;
            });

        return $flagged;
    }

    public function applyNightlyLateFees(): int
    {
        $updated = 0;

        Contribution::query()
            ->where('status', 'pending')
            ->whereIn('collection_status', [
                ContributionCollectionStatus::OVERDUE,
                ...ContributionCollectionStatus::lateStates(),
            ])
            ->whereNotNull('overdue_since')
            ->with('member')
            ->each(function (Contribution $contribution) use (&$updated): void {
                if (! $this->contributionEligibleForArrearLateFees($contribution)) {
                    return;
                }

                if ($this->applyLateFeeTierForContribution($contribution)) {
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Remove pending contribution rows before the import arrears cut-off and reverse
     * any late fees already posted against them.
     */
    public function dismissPreCutoffPendingContributions(Member $member): int
    {
        return AccountingService::withoutMemberCashCollection(function () use ($member): int {
            $member = $member->fresh() ?? $member;
            $dismissed = 0;

            Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->with('member')
                ->orderBy('period')
                ->get()
                ->each(function (Contribution $contribution) use ($member, &$dismissed): void {
                    $period = $contribution->period;

                    if ($period === null) {
                        return;
                    }

                    if ($this->memberPeriodEligibleForAutoCollection($member, (int) $period->month, (int) $period->year)) {
                        return;
                    }

                    $lateFeeCollected = $this->accounting->contributionLateFeeCollectedAmount($contribution);

                    DB::transaction(function () use ($contribution, $lateFeeCollected): void {
                        if ($lateFeeCollected > 0.00001) {
                            $this->accounting->reverseContributionLateFee($contribution, $lateFeeCollected);
                        }

                        $contribution->transactions()->delete();

                        $contribution->update([
                            'late_fee_amount' => null,
                            'late_fee_tier' => null,
                            'overdue_since' => null,
                            'is_late' => false,
                            'notes' => trim(($contribution->notes ?? '').' '.__('Dismissed: before contribution arrears cut-off.')),
                        ]);

                        DB::table('contributions')->where('id', $contribution->id)->delete();
                    });

                    $dismissed++;
                });

            return $dismissed;
        });
    }

    public function applyLateFeeTierForContribution(Contribution $contribution): bool
    {
        if (! $this->contributionEligibleForArrearLateFees($contribution)) {
            return false;
        }

        if ($contribution->overdue_since === null) {
            return false;
        }

        $days = $this->lateFees->daysPastDue(
            Carbon::parse($contribution->overdue_since),
            BusinessDay::now(),
        );

        $newTier = ContributionCollectionStatus::tierForDays($days);
        $currentTier = (int) ($contribution->late_fee_tier ?? 0);

        if ($newTier === null) {
            return false;
        }

        if ($newTier === $currentTier) {
            return false;
        }

        $newFee = $this->lateFees->contributionLateFeeForTier($newTier);
        $oldFee = (float) ($contribution->late_fee_amount ?? 0);

        $feeToPost = ContributionPolicySettings::lateFeeModel() === 'cumulative'
            ? max(0.0, $newFee - $oldFee)
            : $newFee;

        if ($feeToPost > 0.00001 && AccountingService::memberCashCollectionInProgress()) {
            $contribution->loadMissing('member');
            $contribution->member->unsetRelation('accounts');

            if ($feeToPost > $contribution->member->getCashBalance() + 0.00001) {
                return false;
            }
        }

        DB::transaction(function () use ($contribution, $newTier, $newFee, $oldFee, $days, $feeToPost): void {
            if (ContributionPolicySettings::lateFeeModel() === 'replacement' && $oldFee > 0.00001) {
                $this->accounting->reverseContributionLateFee($contribution, $oldFee);
            }

            if ($feeToPost > 0.00001) {
                $this->accounting->postContributionLateFee($contribution, $feeToPost);
            }

            $contribution->update([
                'late_fee_tier' => $newTier,
                'late_fee_amount' => $newFee > 0 ? $newFee : null,
                'collection_status' => ContributionCollectionStatus::labelForTier($newTier),
                'is_late' => $days > ContributionPolicySettings::lateFeeReminderDays(),
            ]);
        });

        return true;
    }

    protected function postFullCollection(Contribution $contribution, float $shortfall, float $lateFeeDue): string
    {
        DB::transaction(function () use ($contribution, $shortfall, $lateFeeDue): void {
            if ($shortfall > 0.00001) {
                $this->accounting->postContributionPrincipal($contribution, $shortfall);
            }

            if ($lateFeeDue > 0.00001) {
                $this->accounting->postContributionLateFee($contribution, $lateFeeDue);
            }

            $this->markCollected($contribution);
        });

        $this->afterContributionPosted($contribution);

        return 'collected';
    }

    protected function postPartialCollection(
        Contribution $contribution,
        float $debitAmount,
        float $lateFee,
        float $shortfall,
    ): string {
        $latePortion = min($lateFee, $debitAmount);
        $principalPortion = $debitAmount - $latePortion;

        if ($principalPortion > $shortfall + 0.00001) {
            $principalPortion = $shortfall;
            $latePortion = $debitAmount - $principalPortion;
        }

        DB::transaction(function () use ($contribution, $principalPortion, $latePortion): void {
            if ($principalPortion > 0.00001) {
                $this->accounting->postContributionPrincipal($contribution, $principalPortion);
            }

            if ($latePortion > 0.00001) {
                $this->accounting->postContributionLateFee($contribution, $latePortion);
            }

            $newCollected = (float) ($contribution->amount_collected ?? 0) + $principalPortion;

            $contribution->update([
                'amount_collected' => $newCollected,
                'collection_status' => ContributionCollectionStatus::PARTIALLY_PENDING,
            ]);
        });

        return 'partial';
    }

    protected function markCollected(Contribution $contribution): void
    {
        $amountDue = (float) ($contribution->amount_due ?? $contribution->amount);
        $preserveLate = $this->contributionShouldRemainMarkedLate($contribution);

        $contribution->update([
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'amount_collected' => $amountDue,
            'posted_at' => BusinessDay::now(),
            'paid_at' => $contribution->paid_at ?? BusinessDay::now(),
            'is_late' => $preserveLate,
        ]);
    }

    protected function contributionShouldRemainMarkedLate(Contribution $contribution): bool
    {
        if ($contribution->is_late) {
            return true;
        }

        if ($contribution->overdue_since !== null) {
            return true;
        }

        return in_array($contribution->collection_status, [
            ContributionCollectionStatus::OVERDUE,
            ...ContributionCollectionStatus::lateStates(),
        ], true);
    }

    protected function afterContributionPosted(Contribution $contribution): void
    {
        app(ContributionService::class)->notifyMemberOfPostedContribution($contribution);

        if (LoanSettings::autoAllocateLoanRepayment()) {
            app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($contribution->member);
        }
    }

    protected function findOpenContribution(Member $member, int $month, int $year): ?Contribution
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->where('status', 'pending')
            ->first();
    }

    protected function contributionRecordMissingForPeriod(Member $member, int $month, int $year): bool
    {
        return ! Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->exists();
    }

    /**
     * Whether auto-collection may touch this period (respects import arrears cut-off).
     */
    protected function memberPeriodEligibleForAutoCollection(Member $member, int $month, int $year): bool
    {
        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        if ((float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        $liabilityStart = $member->contributionLiabilityStartMonth();

        if ($liabilityStart === null) {
            return false;
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();

        return $periodStart->greaterThanOrEqualTo($liabilityStart);
    }

    protected function contributionEligibleForArrearLateFees(Contribution $contribution): bool
    {
        $contribution->loadMissing('member');
        $period = $contribution->period;

        if ($period === null || $contribution->member === null) {
            return false;
        }

        return $this->memberPeriodEligibleForAutoCollection(
            $contribution->member,
            (int) $period->month,
            (int) $period->year,
        );
    }
}
