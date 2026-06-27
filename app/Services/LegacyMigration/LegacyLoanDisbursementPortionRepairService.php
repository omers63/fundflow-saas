<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates member/master disbursement portions for legacy-imported loans using
 * historical payment replay, then repairs loan records and missing principal recognition.
 */
final class LegacyLoanDisbursementPortionRepairService
{
    private const float TOLERANCE = 0.02;

    public function __construct(
        private readonly LegacyImportedLoanInstallmentRebuildService $installmentRebuild,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
        private readonly LegacyExcessLoanRepaymentRepairService $excessRepaymentRepair,
        private readonly LoanLedgerService $ledger,
        private readonly AccountingService $accounting,
        private readonly LegacyMigrationZeroBalanceLoanCompletionService $zeroBalanceCompletion,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     repaired: int,
     *     loan_ids: list<int>,
     *     errors: list<string>,
     * }
     */
    public function repairFromPaymentsCsv(
        string $paymentsCsvPath,
        ?string $fundingStrategy = LoanFundingStrategy::MEMBER_FUND_TOPUP,
    ): array {
        if (! is_readable($paymentsCsvPath)) {
            throw new \InvalidArgumentException(__('Payments CSV is not readable: :path', ['path' => $paymentsCsvPath]));
        }

        $strategy = LoanFundingStrategy::normalize($fundingStrategy);
        $simulator = LegacyMigrationLoanFundingSimulator::forLegacyMigration($paymentsCsvPath);

        $scanned = 0;
        $repaired = 0;
        /** @var list<int> $loanIds */
        $loanIds = [];
        /** @var list<int> $memberIdsNeedingScheduleSync */
        $memberIdsNeedingScheduleSync = [];
        /** @var list<string> $errors */
        $errors = [];

        $loans = Loan::query()
            ->with(['member', 'loanTier', 'disbursements'])
            ->where('funding_strategy', $strategy)
            ->whereNotNull('disbursed_at')
            ->orderBy('disbursed_at')
            ->orderBy('id')
            ->get();

        foreach ($loans as $loan) {
            $scanned++;
            $member = $loan->member;

            if ($member === null) {
                continue;
            }

            $expected = $this->expectedPortions($simulator, $loan, $member, $strategy);

            if ($this->portionsMatch($loan, $expected)) {
                $simulator->recordDisbursement($member, $loan->disbursed_at, $expected['member_portion']);

                continue;
            }

            try {
                $this->applyPortionCorrection($loan, $expected);
                $simulator->recordDisbursement($member, $loan->disbursed_at, $expected['member_portion']);
                $repaired++;
                $loanIds[] = (int) $loan->id;
                $memberIdsNeedingScheduleSync[(int) $member->id] = true;
            } catch (\Throwable $exception) {
                $errors[] = __('Loan #:id: :message', [
                    'id' => $loan->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($loanIds !== []) {
            $this->installmentRebuild->rebuildImplicitPortionLoans();

            Loan::query()
                ->whereIn('id', $loanIds)
                ->each(function (Loan $loan): void {
                    $loan->refresh();

                    if (! $loan->hasNoRepaymentScheduleObligation()) {
                        return;
                    }

                    $loan->completeAsFullyMemberFundedLegacyImport($loan->disbursed_at);

                    if ($loan->fresh()->repayments()->exists()) {
                        $this->excessRepaymentRepair->repairLoan($loan->fresh());
                    }
                });

            $this->scheduleSync->syncMembers(array_keys($memberIdsNeedingScheduleSync));
            $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines(reconcileLineBalances: false);
        }

        $this->zeroBalanceCompletion->completeAll();

        return [
            'scanned' => $scanned,
            'repaired' => $repaired,
            'loan_ids' => $loanIds,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{member_portion: float, master_portion: float}
     */
    private function expectedPortions(
        LegacyMigrationLoanFundingSimulator $simulator,
        Loan $loan,
        Member $member,
        string $strategy,
    ): array {
        $approved = round((float) $loan->amount_approved, 2);
        $fundBalance = $simulator->fundBalanceBeforeDisbursement($member, $loan->disbursed_at);

        return LoanSettings::resolveFundingPortions($approved, $fundBalance, $strategy);
    }

    /**
     * @param  array{member_portion: float, master_portion: float}  $expected
     */
    private function portionsMatch(Loan $loan, array $expected): bool
    {
        return abs((float) $loan->member_portion - $expected['member_portion']) <= self::TOLERANCE
            && abs((float) $loan->master_portion - $expected['master_portion']) <= self::TOLERANCE;
    }

    /**
     * @param  array{member_portion: float, master_portion: float}  $expected
     */
    private function applyPortionCorrection(Loan $loan, array $expected): void
    {
        $previousMemberPortion = round((float) $loan->member_portion, 2);
        $memberPortion = round($expected['member_portion'], 2);
        $masterPortion = round($expected['master_portion'], 2);
        $approved = round((float) $loan->amount_approved, 2);
        $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? 1000);
        $threshold = (float) $loan->settlement_threshold;
        $installmentCount = Loan::computeInstallmentsCountFromPortions(
            $approved,
            $memberPortion,
            $minInstall,
            $threshold,
        );

        DB::transaction(function () use ($loan, $memberPortion, $masterPortion, $installmentCount, $previousMemberPortion): void {
            $loan->update([
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'installments_count' => $installmentCount,
                'term_months' => $installmentCount,
            ]);

            $loan->disbursements()->each(function (LoanDisbursement $disbursement) use ($memberPortion, $masterPortion): void {
                $disbursement->update([
                    'member_portion' => $memberPortion,
                    'master_portion' => $masterPortion,
                ]);
            });

            if ($memberPortion > $previousMemberPortion + self::TOLERANCE) {
                $loan->refresh();
                $this->ledger->recognizeMemberPortionAgainstLoanPrincipal(
                    $loan,
                    $loan->disbursed_at,
                );
            }
        });
    }
}
