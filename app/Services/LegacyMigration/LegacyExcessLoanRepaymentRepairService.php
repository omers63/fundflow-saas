<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\Loans\LoanLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Converts legacy loan repayments that exceed the fund-portion target back to contributions.
 */
final class LegacyExcessLoanRepaymentRepairService
{
    public function __construct(
        private readonly AccountingService $accounting,
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
        $cumulative = 0.0;

        $repayments = $loan->repayments()
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        ContributionService::withoutPostedNotifications(function () use ($member, $loan, $repayments, $target, &$cumulative, &$stats): void {
            ContributionService::withoutLiveCollectionGuards(function () use ($member, $loan, $repayments, $target, &$cumulative, &$stats): void {
                AccountingService::withoutMemberCashCollection(function () use ($member, $loan, $repayments, $target, &$cumulative, &$stats): void {
                    foreach ($repayments as $repayment) {
                        $amount = (float) $repayment->amount;
                        $allowed = LegacyLoanRepaymentTarget::remainingFundPortionObligation($target, $cumulative);

                        if ($allowed <= LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                            $this->reverseRepaymentToContribution($loan, $member, $repayment);
                            $stats['repayments_reversed']++;
                            $stats['contributions_posted']++;

                            continue;
                        }

                        if ($amount > $allowed + LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                            $excess = round($amount - $allowed, 2);
                            $paidAt = Carbon::parse((string) $repayment->paid_at);
                            $notes = $repayment->notes ?: __('Legacy migration loan repayment');

                            $this->reverseImportedLoanRepayment($loan, $repayment);

                            if ($allowed > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
                                $affectedLoanIds = [];
                                $cumulativeRepaidByLoanKey = [];
                                $this->paymentImport->postAllocatedLoanRepaymentForRepair(
                                    $loan,
                                    $allowed,
                                    $paidAt,
                                    $notes,
                                    $affectedLoanIds,
                                    $cumulativeRepaidByLoanKey,
                                );
                            }

                            $this->postContributionRemainder($member, $excess, $paidAt, $notes);
                            $stats['repayments_resplit']++;
                            $stats['contributions_posted']++;
                            $cumulative += $allowed;

                            continue;
                        }

                        $cumulative += $amount;
                    }
                });
            });
        });

        $sync = $this->scheduleSync->syncLoans([$loan->id]);
        $stats['installments_marked'] = $sync['installments'];

        $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines();

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

        return $totals;
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

        $month = (int) $paidAt->month;
        $year = (int) $paidAt->year;

        if (Contribution::memberPeriodRecordExists($member->id, $month, $year)) {
            $month = (int) $paidAt->copy()->addMonth()->month;
            $year = (int) $paidAt->copy()->addMonth()->year;
        }

        $affectedLoanIds = [];
        $cumulativeRepaidByLoanKey = [];

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
