<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class LegacyLoanRepaymentReimportService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly LegacyPaymentClassifierService $classifier,
        private readonly LegacyPaymentImportService $payments,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
    ) {}

    /**
     * @return array{
     *     reversed_transactions: int,
     *     deleted_repayments: int,
     *     reset_installments: int,
     *     reset_loans: int
     * }
     */
    public function resetImportedLoanRepayments(): array
    {
        @set_time_limit(0);

        $reversedTransactions = 0;
        $deletedRepayments = 0;
        $resetInstallments = 0;
        $resetLoans = 0;

        DB::transaction(function () use (&$reversedTransactions, &$deletedRepayments, &$resetInstallments, &$resetLoans): void {
            $affectedLoanIds = LoanRepayment::query()->distinct()->pluck('loan_id');

            Transaction::query()
                ->where('reference_type', Loan::class)
                ->where('description', 'like', '%repayments (import, bulk)%')
                ->orderBy('id')
                ->chunkById(200, function ($transactions) use (&$reversedTransactions): void {
                    foreach ($transactions as $transaction) {
                        $this->accounting->createReversalEntry(
                            $transaction,
                            __('Legacy loan repayment re-import reset'),
                        );
                        $reversedTransactions++;
                    }
                });

            $deletedRepayments = LoanRepayment::query()->count();
            LoanRepayment::query()->delete();

            $resetInstallments = LoanInstallment::query()
                ->where('status', 'paid')
                ->update([
                    'status' => 'pending',
                    'paid_at' => null,
                    'is_late' => false,
                    'late_fee_amount' => 0,
                ]);

            $resetLoans = Loan::query()
                ->where('status', 'completed')
                ->update([
                    'status' => 'active',
                    'settled_at' => null,
                ]);

            if ($affectedLoanIds->isNotEmpty()) {
                Loan::query()
                    ->whereIn('id', $affectedLoanIds)
                    ->update(['repaid_to_master' => 0]);
            }
        });

        return [
            'reversed_transactions' => $reversedTransactions,
            'deleted_repayments' => $deletedRepayments,
            'reset_installments' => $resetInstallments,
            'reset_loans' => $resetLoans,
        ];
    }

    /**
     * @return array{
     *     classification: array{contribution: int, loan_repayment: int, ignore: int, unclassified: int, failed: int},
     *     classified_path: string,
     *     import: array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>},
     *     schedule: array{loans: int, installments: int}
     * }
     */
    public function reclassifyAndImport(
        string $paymentsPath,
        string $classifiedOutputPath,
        ?string $membersPath = null,
        ?string $loansPath = null,
        ?Carbon $cutoffDate = null,
    ): array {
        @set_time_limit(0);

        $classification = $this->classifier->classifyFile(
            $paymentsPath,
            $cutoffDate,
            $membersPath,
            $loansPath,
        );

        $this->classifier->writeClassifiedCsv($classifiedOutputPath, $classification['rows']);

        $import = ContributionService::withoutPostedNotifications(
            fn (): array => ContributionService::withoutLiveCollectionGuards(
                fn (): array => $this->payments->import($classifiedOutputPath, $loansPath),
            ),
        );

        $schedule = $this->scheduleSync->syncAllLoansWithImportedRepayments();

        return [
            'classification' => $classification['stats'],
            'classified_path' => $classifiedOutputPath,
            'import' => $import,
            'schedule' => $schedule,
        ];
    }
}
