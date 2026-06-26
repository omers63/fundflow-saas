<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Support\LoanRepaymentWindowPolicy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds installment schedules for legacy-imported loans whose ledger portions
 * (full member fund debit) were incorrectly used to derive EMI counts.
 */
final class LegacyImportedLoanInstallmentRebuildService
{
    public function __construct(
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
    ) {}

    /**
     * @return array{loans: int, installments: int}
     */
    public function rebuildImplicitPortionLoans(?int $loanId = null): array
    {
        $loansRebuilt = 0;
        $installmentsCreated = 0;

        $query = Loan::query()
            ->with('loanTier')
            ->whereNotNull('disbursed_at')
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled']);

        if ($loanId !== null) {
            $query->whereKey($loanId);
        }

        foreach ($query->orderBy('id')->cursor() as $loan) {
            if (! $this->usesImplicitLegacyLedgerPortions($loan)) {
                continue;
            }

            if ($loan->hasNoRepaymentScheduleObligation()) {
                $loan->completeAsFullyMemberFundedLegacyImport();
                $loansRebuilt++;

                continue;
            }

            $expectedCount = $this->expectedInstallmentCount($loan);

            if ($expectedCount === $loan->installments()->count()) {
                continue;
            }

            $installmentsCreated += $this->rebuildLoanSchedule($loan, $expectedCount);
            $loansRebuilt++;
        }

        return [
            'loans' => $loansRebuilt,
            'installments' => $installmentsCreated,
        ];
    }

    public function usesImplicitLegacyLedgerPortions(Loan $loan): bool
    {
        $approved = (float) $loan->amount_approved;

        if ($approved <= 0) {
            return false;
        }

        $memberPortion = (float) $loan->member_portion;
        $masterPortion = (float) $loan->master_portion;

        return abs($masterPortion) < 0.02
            && abs($memberPortion - $approved) < 0.02;
    }

    public function expectedInstallmentCount(Loan $loan): int
    {
        $amount = (float) $loan->amount_approved;
        $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? 1000);
        $threshold = (float) ($loan->settlement_threshold ?? LoanSettings::settlementThreshold());

        return Loan::computeInstallmentsCountFromPortions(
            $amount,
            (float) $loan->member_portion,
            $minInstall,
            $threshold,
        );
    }

    private function rebuildLoanSchedule(Loan $loan, int $count): int
    {
        $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? 1000);
        $disbursedAt = Carbon::parse((string) $loan->disbursed_at);
        $policy = app(LoanRepaymentWindowPolicy::class);
        $firstPeriod = $loan->first_repayment_year !== null && $loan->first_repayment_month !== null
            ? Carbon::create((int) $loan->first_repayment_year, (int) $loan->first_repayment_month, 1)
            : $disbursedAt->copy()->addMonthNoOverflow()->startOfMonth();

        DB::transaction(function () use ($loan, $count, $minInstall, $disbursedAt, $firstPeriod, $policy): void {
            $loan->installments()->forceDelete();

            for ($i = 1; $i <= $count; $i++) {
                $period = $firstPeriod->copy()->addMonths($i - 1);

                LoanInstallment::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $i,
                    'amount' => $minInstall,
                    'due_date' => $policy->installmentDueDateForCycle(
                        (int) $period->month,
                        (int) $period->year,
                    )->toDateString(),
                    'status' => 'pending',
                ]);
            }

            $loan->update([
                'installments_count' => $count,
                'due_date' => $disbursedAt->copy()->addMonths($count)->toDateString(),
                'status' => 'active',
                'settled_at' => null,
            ]);
        });

        $this->scheduleSync->syncLoan($loan->fresh());
        $loan->fresh()->syncPaidOffStatusFromInstallments();

        return $count;
    }
}
