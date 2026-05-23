<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanInstallmentCollectionService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\LoanSettings;
use Carbon\Carbon;
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

            if (
                Contribution::query()
                    ->where('member_id', $member->id)
                    ->where('period', $period)
                    ->exists()
            ) {
                return;
            }

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

    public function attemptCollection(Contribution $contribution): string
    {
        if (
            $contribution->status === 'posted'
            || $contribution->collection_status === ContributionCollectionStatus::COLLECTED
        ) {
            return 'collected';
        }

        $contribution->loadMissing('member');
        $member = $contribution->member;
        $amountDue = (float) ($contribution->amount_due ?? $contribution->amount);
        $collected = (float) ($contribution->amount_collected ?? 0);
        $shortfall = max(0.0, $amountDue - $collected);
        $lateFee = (float) ($contribution->late_fee_amount ?? 0);
        $required = $shortfall + $lateFee;

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

            return $this->postPartialCollection($contribution, $debitAmount, $lateFee, $shortfall);
        }

        $contribution->update(['collection_status' => ContributionCollectionStatus::SETTLING]);

        return $this->postFullCollection($contribution, $shortfall, $lateFee);
    }

    /**
     * Re-evaluate open contributions when member cash increases (deposit accepted, bank post, etc.).
     */
    public function onMemberCashIncreased(Member $member): void
    {
        Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->each(function (Contribution $contribution): void {
                $this->attemptCollection($contribution);
            });

        app(LoanInstallmentCollectionService::class)
            ->onMemberCashIncreased($member);
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
                if ($this->applyLateFeeTierForContribution($contribution)) {
                    $updated++;
                }
            });

        return $updated;
    }

    public function applyLateFeeTierForContribution(Contribution $contribution): bool
    {
        if ($contribution->overdue_since === null) {
            return false;
        }

        $days = $this->lateFees->daysPastDue(
            Carbon::parse($contribution->overdue_since),
            now(),
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

    protected function postFullCollection(Contribution $contribution, float $shortfall, float $lateFee): string
    {
        DB::transaction(function () use ($contribution, $shortfall, $lateFee): void {
            if ($shortfall > 0.00001) {
                $this->accounting->postContributionPrincipal($contribution, $shortfall);
            }

            $remainingLateFee = max(0.0, $lateFee - (float) ($contribution->late_fee_amount ?? 0));

            if ($remainingLateFee > 0.00001) {
                $this->accounting->postContributionLateFee($contribution, $remainingLateFee);
                $contribution->update(['late_fee_amount' => $lateFee]);
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

        $contribution->update([
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'amount_collected' => $amountDue,
            'posted_at' => now(),
            'paid_at' => $contribution->paid_at ?? now(),
        ]);
    }

    protected function afterContributionPosted(Contribution $contribution): void
    {
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
}
