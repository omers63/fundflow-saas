<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Loans\LoanLedgerService;
use App\Support\LoanRepaymentNote;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Converts legacy loan repayments that exceed the fund-portion target back to contributions.
 */
final class LegacyExcessLoanRepaymentRepairService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly ContributionCycleService $cycles,
        private readonly ContributionService $contributions,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
        private readonly LegacyPaymentImportService $paymentImport,
    ) {}

    /**
     * @return array{
     *     loans_processed: int,
     *     repayments_reversed: int,
     *     repayments_resplit: int,
     *     contributions_posted: int,
     *     installments_marked: int
     * }
     */
    public function repairLoan(Loan $loan): array
    {
        @set_time_limit(0);

        $stats = [
            'loans_processed' => 1,
            'repayments_reversed' => 0,
            'repayments_resplit' => 0,
            'contributions_posted' => 0,
            'installments_marked' => 0,
        ];

        $loan->loadMissing('member');
        $member = $loan->member;
        $target = LegacyLoanRepaymentTarget::forLoan($loan);

        $repayments = $loan->repayments()
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $excess = round((float) $repayments->sum('amount') - $target, 2);

        ContributionService::withoutPostedNotifications(function () use ($member, $loan, $repayments, &$excess, &$stats): void {
            ContributionService::withoutLiveCollectionGuards(function () use ($member, $loan, $repayments, &$excess, &$stats): void {
                AccountingService::withoutMemberCashCollection(function () use ($member, $loan, $repayments, &$excess, &$stats): void {
                    // Prefer peeling excess from legacy/import rows so later EMI / guarantor
                    // installment logs (ff:installment:…) stay tied to the schedule they paid.
                    $this->peelExcessToContributions($loan, $member, $repayments, $excess, $stats, skipProtectedOperational: true);

                    if ($excess > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                        $remaining = $loan->repayments()
                            ->orderBy('paid_at')
                            ->orderBy('id')
                            ->get();
                        $this->peelExcessToContributions($loan, $member, $remaining, $excess, $stats, skipProtectedOperational: false);
                    }
                });
            });
        });

        $sync = $this->scheduleSync->syncLoans([$loan->id]);
        $stats['installments_marked'] = $sync['installments'];

        $loan->refresh();

        if ($loan->isFullyMemberFundedAtDisbursement() && $loan->hasNoRepaymentScheduleObligation()) {
            $loan->completeAsFullyMemberFundedLegacyImport();
        }

        return $stats;
    }

    /**
     * @return array{
     *     loans_processed: int,
     *     repayments_reversed: int,
     *     repayments_resplit: int,
     *     contributions_posted: int,
     *     installments_marked: int
     * }
     */
    public function repairLoans(iterable $loans): array
    {
        $totals = [
            'loans_processed' => 0,
            'repayments_reversed' => 0,
            'repayments_resplit' => 0,
            'contributions_posted' => 0,
            'installments_marked' => 0,
        ];

        foreach ($loans as $loan) {
            if (! $loan instanceof Loan) {
                continue;
            }

            $result = $this->repairLoan($loan);
            $totals['loans_processed']++;
            $totals['repayments_reversed'] += $result['repayments_reversed'];
            $totals['repayments_resplit'] += $result['repayments_resplit'];
            $totals['contributions_posted'] += $result['contributions_posted'];
            $totals['installments_marked'] += $result['installments_marked'];
        }

        if ($totals['loans_processed'] > 0) {
            $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines();
        }

        return $totals;
    }

    /**
     * @param  Collection<int, LoanRepayment>  $repayments
     * @param  array{
     *     loans_processed: int,
     *     repayments_reversed: int,
     *     repayments_resplit: int,
     *     contributions_posted: int,
     *     installments_marked: int
     * }  $stats
     */
    private function peelExcessToContributions(
        Loan $loan,
        Member $member,
        Collection $repayments,
        float &$excess,
        array &$stats,
        bool $skipProtectedOperational,
    ): void {
        if ($excess <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            return;
        }

        $ordered = $repayments
            ->sortBy([
                ['paid_at', 'desc'],
                ['id', 'desc'],
            ])
            ->values();

        foreach ($ordered as $repayment) {
            if ($excess <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                break;
            }

            if (! $repayment instanceof LoanRepayment) {
                continue;
            }

            if ($skipProtectedOperational && $this->isProtectedOperationalRepayment($repayment)) {
                continue;
            }

            $amount = round((float) $repayment->amount, 2);

            if ($amount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                continue;
            }

            if ($amount <= $excess + LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                $this->reverseRepaymentToContribution($loan, $member, $repayment);
                $stats['repayments_reversed']++;
                $stats['contributions_posted']++;
                $excess = round($excess - $amount, 2);

                continue;
            }

            $keep = round($amount - $excess, 2);
            $move = $excess;
            $paidAt = Carbon::parse((string) $repayment->paid_at);
            $notes = $repayment->notes ?: __('Legacy migration loan repayment');

            $this->reverseImportedLoanRepayment($loan, $repayment);

            if ($keep > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                $affectedLoanIds = [];
                $cumulativeRepaidByLoanKey = [];
                $this->paymentImport->postAllocatedLoanRepaymentForRepair(
                    $loan,
                    $keep,
                    $paidAt,
                    $notes,
                    $affectedLoanIds,
                    $cumulativeRepaidByLoanKey,
                );
            }

            $this->postContributionRemainder($member, $move, $paidAt, $notes);
            $stats['repayments_resplit']++;
            $stats['contributions_posted']++;
            $excess = 0.0;
        }
    }

    private function isProtectedOperationalRepayment(LoanRepayment $repayment): bool
    {
        $notes = (string) $repayment->notes;

        return LoanRepaymentNote::installmentNumber($notes) !== null
            || LoanRepaymentNote::isSettlement($notes);
    }

    private function reverseRepaymentToContribution(Loan $loan, Member $member, LoanRepayment $repayment): void
    {
        $amount = (float) $repayment->amount;
        $paidAt = Carbon::parse((string) $repayment->paid_at);
        $notes = ($repayment->notes ?: __('Legacy migration loan repayment'))
            .' ['.__('Repaired — excess repayment after fund portion satisfied').']';

        $this->reverseImportedLoanRepayment($loan, $repayment);
        $this->postContributionRemainder($member, $amount, $paidAt, $notes);
    }

    private function reverseImportedLoanRepayment(Loan $loan, LoanRepayment $repayment): void
    {
        DB::transaction(function () use ($loan, $repayment): void {
            $lockedLoan = Loan::query()->lockForUpdate()->findOrFail($loan->id);
            $amount = (float) $repayment->amount;
            $repaidSlice = LoanLedgerService::principalAmountCreditingMasterRepaidSlice(
                (float) $lockedLoan->master_portion,
                (float) $lockedLoan->repaid_to_master,
                $amount,
            );

            $repayment->transactions()
                ->orderByDesc('id')
                ->get()
                ->each(fn (Transaction $transaction) => $this->accounting->deleteTransaction($transaction));

            if ($repaidSlice > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                $lockedLoan->decrement('repaid_to_master', $repaidSlice);
            }

            $repayment->delete();
        });
    }

    private function postContributionRemainder(Member $member, float $amount, Carbon $paidAt, string $notes): void
    {
        if ($amount <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            return;
        }

        [$month, $year] = $this->cycles->cyclePeriodForDueDate($paidAt);

        $this->paymentImport->postLegacyContributionForRepair(
            $member,
            $month,
            $year,
            $amount,
            $paidAt,
            $notes.' ['.__('Repaired — contribution remainder after loan allocation').']',
        );
    }
}
