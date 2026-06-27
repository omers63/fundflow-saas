<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\Loans\LoanImportService;
use App\Services\MemberImportService;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationFundingStrategySettings;
use App\Support\LegacyMigrationGraceCycleSettings;
use App\Support\LegacyMigrationSettlementThresholdSettings;
use App\Support\LoanFundingStrategy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

final class LegacyMigrationOrchestrator
{
    public function __construct(
        private readonly MemberImportService $members,
        private readonly LoanImportService $loans,
        private readonly LegacyPaymentImportService $payments,
        private readonly LegacyMigrationPreviewService $preview,
        private readonly LegacyPaymentClassifierService $classifier,
        private readonly AccountingService $accounting,
        private readonly LegacyExcessLoanRepaymentRepairService $excessRepaymentRepair,
        private readonly LegacyMigrationZeroBalanceLoanCompletionService $zeroBalanceLoanCompletion,
    ) {}

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical'
     * }  $options
     * @return array{
     *     members: array{created: int, skipped: int, failed: int, errors: list<string>},
     *     loans?: array{created: int, failed: int, errors: list<string>},
     *     payments?: array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}
     * }
     */
    public function run(array $options, bool $dryRun = false): array
    {
        if (! $dryRun) {
            @set_time_limit(0);
        }

        $cutoff = filled($options['cutoff_date'] ?? null)
            ? Carbon::parse((string) $options['cutoff_date'])->toDateString()
            : null;

        $memberPreview = $this->preview->previewMembers($options['members_path']);

        if ($memberPreview === null || $memberPreview['missing_columns'] !== []) {
            throw new \InvalidArgumentException(__('Members CSV is missing required columns: :columns', [
                'columns' => implode(', ', $memberPreview['missing_columns'] ?? ['name', 'email']),
            ]));
        }

        if ($dryRun) {
            return self::summarizeForDisplay([
                'members' => [
                    'created' => $memberPreview['row_count'],
                    'skipped' => 0,
                    'failed' => 0,
                    'errors' => $memberPreview['warnings'],
                ],
                'loans' => $this->dryRunLoans($options['loans_path'] ?? null),
                'payments' => $this->dryRunPayments($options),
            ]);
        }

        return self::summarizeForDisplay($this->importMembersLoansAndPayments($options, $cutoff));
    }

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical'
     * }  $options
     * @return array{
     *     members: array{created: int, skipped: int, failed: int, errors: list<string>},
     *     loans?: array{created: int, failed: int, errors: list<string>},
     *     payments?: array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}
     * }
     */
    public function importMembers(array $options, ?string $cutoff = null): array
    {
        @set_time_limit(0);

        $this->validateMembersCsv($options['members_path']);

        $cutoff ??= filled($options['cutoff_date'] ?? null)
            ? Carbon::parse((string) $options['cutoff_date'])->toDateString()
            : null;

        $members = $this->members->import(
            $options['members_path'],
            $options['default_password'],
            $cutoff,
        );

        $this->assertAllCsvMembersPresent((string) $options['members_path'], $members);

        return ['members' => $members];
    }

    /**
     * @param  array{
     *     loans_path: string,
     *     strategy?: 'snapshot'|'historical',
     *     grace_cycles?: int|string|null,
     *     loan_funding_strategy?: string|null,
     *     payments_path?: string|null,
     *     skip_settlement_threshold?: bool|string|int|null,
     * }  $options
     * @return array{loans: array{created: int, failed: int, errors: list<string>}}
     */
    public function importLoans(array $options): array
    {
        @set_time_limit(0);

        $loansPath = $options['loans_path'] ?? null;

        if (! is_string($loansPath) || $loansPath === '' || ! is_readable($loansPath)) {
            throw new \InvalidArgumentException(__('Loans CSV is required.'));
        }

        return [
            'loans' => $this->loans->import(
                $loansPath,
                $this->resolveGraceCycles($options),
                $this->resolveFundingStrategy($options),
                $this->resolvePaymentsPathForLoanFunding($options),
                $this->resolveSkipSettlementThreshold($options),
            ),
        ];
    }

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical'
     * }  $options
     * @return array{
     *     members: array{created: int, skipped: int, failed: int, errors: list<string>},
     *     loans?: array{created: int, failed: int, errors: list<string>},
     *     payments?: array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}
     * }
     */
    public function importMembersAndLoans(array $options, ?string $cutoff = null): array
    {
        $result = $this->importMembers($options, $cutoff);

        if (filled($options['loans_path'] ?? null)) {
            $result['loans'] = $this->importLoans($options)['loans'];
        }

        return $result;
    }

    /**
     * Classify payments from the uploaded members, loans, and payments CSV files.
     *
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password?: string|null,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical',
     *     grace_cycles?: int|string|null,
     *     loan_funding_strategy?: string|null,
     *     skip_settlement_threshold?: bool|string|int|null,
     * }  $options
     * @return array{
     *     rows: list<array<string, string>>,
     *     stats: array{
     *         contributions: int,
     *         future_contributions: int,
     *         loan_repayments: int,
     *         reclassified_as_contribution: int,
     *         failed: int
     *     },
     *     errors: list<string>
     * }
     */
    public function previewPaymentClassification(array $options): array
    {
        $cutoff = filled($options['cutoff_date'] ?? null)
            ? Carbon::parse((string) $options['cutoff_date'])->toDateString()
            : null;

        $paymentsPath = $options['payments_path'] ?? null;

        if (! is_string($paymentsPath) || $paymentsPath === '' || ! is_readable($paymentsPath)) {
            throw new \InvalidArgumentException(__('Payments CSV is required for classification.'));
        }

        $result = $this->classifier->classifyFile(
            $paymentsPath,
            $cutoff !== null ? Carbon::parse($cutoff) : null,
            is_string($options['members_path'] ?? null) ? $options['members_path'] : null,
            is_string($options['loans_path'] ?? null) ? $options['loans_path'] : null,
            $this->resolveGraceCycles($options),
        );

        return [
            'rows' => array_map(
                fn (array $row): array => [
                    ...$row,
                    'migration_outcome' => match ($row['payment_type'] ?? '') {
                        'loan_repayment' => 'loan_repayment',
                        'contribution' => 'contribution',
                        'ignore' => 'ignored',
                        default => (string) ($row['payment_type'] ?? ''),
                    },
                ],
                $result['rows'],
            ),
            'stats' => [
                'contributions' => $result['stats']['contribution'],
                'future_contributions' => 0,
                'loan_repayments' => $result['stats']['loan_repayment'],
                'reclassified_as_contribution' => 0,
                'failed' => $result['stats']['failed'] + ($result['stats']['unclassified'] ?? 0),
            ],
            'errors' => $result['errors'],
        ];
    }

    /**
     * @param  array{
     *     strategy?: 'snapshot'|'historical',
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}|null
     */
    /**
     * Classify uploaded payment rows and persist the canonical classified CSV.
     *
     * Historical import always re-runs this so payment import never reads a stale
     * classified file from an earlier run or a corrupted import pass.
     *
     * @param  array{
     *     cutoff_date?: string|null,
     *     members_path?: string|null,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     grace_cycles?: int|string|null,
     * }  $options
     * @return array{
     *     path: string,
     *     rows: list<array<string, string>>,
     *     stats: array{
     *         contributions: int,
     *         future_contributions: int,
     *         loan_repayments: int,
     *         reclassified_as_contribution: int,
     *         failed: int
     *     },
     *     errors: list<string>
     * }
     */
    public function classifyAndPersistPayments(array $options): array
    {
        $loansPath = $options['loans_path'] ?? null;

        if (! is_string($loansPath) || $loansPath === '' || ! is_readable($loansPath)) {
            throw new \InvalidArgumentException(__('Loans CSV is required for historical payment classification.'));
        }

        $paymentsPath = $options['payments_path'] ?? null;

        if (! is_string($paymentsPath) || $paymentsPath === '' || ! is_readable($paymentsPath)) {
            throw new \InvalidArgumentException(__('Payments CSV is required for classification.'));
        }

        $membersPath = $options['members_path'] ?? null;

        $result = $this->previewPaymentClassification([
            'cutoff_date' => $options['cutoff_date'] ?? null,
            'members_path' => is_string($membersPath) && $membersPath !== '' ? $membersPath : null,
            'loans_path' => $loansPath,
            'payments_path' => $paymentsPath,
            'grace_cycles' => $this->resolveGraceCycles($options),
        ]);

        $absolutePath = Storage::disk('local')->path(
            LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH,
        );

        $this->classifier->writeClassifiedCsv($absolutePath, $result['rows']);

        Setting::set('legacy_migration', 'classify_stats', json_encode($result['stats'], JSON_THROW_ON_ERROR));
        Setting::set('legacy_migration', 'classify_errors', json_encode(
            array_slice($result['errors'] ?? [], 0, 10),
            JSON_THROW_ON_ERROR,
        ));
        Setting::set('legacy_migration', 'classify_status', 'completed');
        Setting::set('legacy_migration', 'classify_error', '');
        Setting::set('legacy_migration', 'classify_inputs', json_encode([
            'members_path' => is_string($membersPath) && $membersPath !== '' ? $membersPath : null,
            'loans_path' => $loansPath,
            'payments_path' => $paymentsPath,
            'classified_at' => now()->toIso8601String(),
            'loans_header' => AssociativeCsv::headers($loansPath),
        ], JSON_THROW_ON_ERROR));

        return [
            'path' => $absolutePath,
            ...$result,
        ];
    }

    public function importPayments(array $options): ?array
    {
        if (($options['strategy'] ?? 'snapshot') !== 'historical') {
            return null;
        }

        return $this->applyClassifiedPayments($options);
    }

    /**
     * Replay the classified payments CSV (step 5). Does not re-import members/loans or re-classify.
     *
     * @param  array{
     *     loans_path?: string|null,
     *     classified_payments_path?: string|null,
     *     grace_cycles?: int|string|null,
     * }  $options
     * @return array{
     *     contributions: int,
     *     loan_repayments: int,
     *     ignored: int,
     *     failed: int,
     *     errors: list<string>,
     *     classification: array{contributions: int, loan_repayments: int, failed: int}
     * }
     */
    public function applyClassifiedPayments(array $options): array
    {
        @set_time_limit(0);

        $classifiedPath = $this->resolveClassifiedPaymentsPath($options);

        if ($classifiedPath === null) {
            throw new \InvalidArgumentException(__('Classified payments CSV is required. Classify payments before applying the migration.'));
        }

        $preImportStats = $this->classifier->summarizeClassifiedFile($classifiedPath);

        $result = ContributionService::withoutPostedNotifications(
            fn (): array => $this->payments->import(
                $classifiedPath,
                is_string($options['loans_path'] ?? null) && is_readable($options['loans_path'])
                ? $options['loans_path']
                : null,
                $this->resolveGraceCycles($options),
                rebuildBalances: false,
            ),
        );

        $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines(reconcileLineBalances: false);

        $this->repairStrayRepaymentsOnFundOnlyLoans();

        $this->reconcileLedgerRunningBalances();

        $loansCompleted = $this->zeroBalanceLoanCompletion->completeAll();

        return [
            ...$result,
            'classification' => [
                'contributions' => $preImportStats['contributions'],
                'loan_repayments' => $preImportStats['loan_repayments'],
                'failed' => $preImportStats['failed'],
            ],
            'loans_completed' => $loansCompleted,
        ];
    }

    /**
     * @param  array{
     *     classified_payments_path?: string|null,
     * }  $options
     * @return array{payments: array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}}
     */
    public function dryRunClassifiedApply(array $options): array
    {
        $classifiedPath = $this->resolveClassifiedPaymentsPath($options);

        if ($classifiedPath === null) {
            throw new \InvalidArgumentException(__('Classified payments CSV is required. Classify payments before applying the migration.'));
        }

        $summary = $this->classifier->summarizeClassifiedFile($classifiedPath);

        return [
            'payments' => [
                'contributions' => $summary['contributions'],
                'loan_repayments' => $summary['loan_repayments'],
                'ignored' => $summary['ignored'] ?? 0,
                'failed' => $summary['failed'],
                'errors' => [],
            ],
        ];
    }

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical'
     * }  $options
     * @return array<string, mixed>
     */
    private function importMembersLoansAndPayments(array $options, ?string $cutoff): array
    {
        $result = $this->importMembersAndLoans($options, $cutoff);

        $payments = $this->importPayments($options);

        if ($payments !== null) {
            $result['payments'] = $payments;
        }

        if (($options['strategy'] ?? 'snapshot') === 'historical') {
            $this->reconcileLedgerRunningBalances();
        }

        return $result;
    }

    private function reconcileLedgerRunningBalances(): void
    {
        $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines();
    }

    private function validateMembersCsv(string $membersPath): void
    {
        $memberPreview = $this->preview->previewMembers($membersPath);

        if ($memberPreview === null || $memberPreview['missing_columns'] !== []) {
            throw new \InvalidArgumentException(__('Members CSV is missing required columns: :columns', [
                'columns' => implode(', ', $memberPreview['missing_columns'] ?? ['name', 'email']),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function summarizeForDisplay(array $result): array
    {
        foreach (['members', 'loans', 'payments'] as $section) {
            if (! is_array($result[$section] ?? null)) {
                continue;
            }

            $errors = $result[$section]['errors'] ?? null;

            if (! is_array($errors) || count($errors) <= 25) {
                continue;
            }

            $result[$section]['errors_total'] = count($errors);
            $result[$section]['errors_truncated'] = true;
            $result[$section]['errors'] = array_slice($errors, 0, 25);
        }

        return $result;
    }

    /**
     * @param  array{created: int, skipped: int, failed: int, errors: list<string>}  $memberResult
     */
    public function assertAllCsvMembersPresent(string $membersPath, array $memberResult): void
    {
        $rows = AssociativeCsv::read($membersPath);
        $missingNumbers = [];

        foreach ($rows as $row) {
            $memberNumber = trim((string) ($row['member_number'] ?? ''));

            if ($memberNumber === '') {
                continue;
            }

            if (! Member::query()->where('member_number', $memberNumber)->exists()) {
                $missingNumbers[] = $memberNumber;
            }
        }

        if ($missingNumbers === []) {
            return;
        }

        $sample = implode(', ', array_slice($missingNumbers, 0, 15));

        if (count($missingNumbers) > 15) {
            $sample .= ' …';
        }

        throw new \RuntimeException(__(
            'Member import is incomplete: :count CSV row(s) are not in the database (e.g. :sample). Reported created :created, skipped :skipped, failed :failed. Fix the CSV or clear partial imports, then run the migration again.',
            [
                'count' => count($missingNumbers),
                'sample' => $sample,
                'created' => $memberResult['created'],
                'skipped' => $memberResult['skipped'],
                'failed' => $memberResult['failed'],
            ],
        ));
    }

    /**
     * @return array{created: int, failed: int, errors: list<string>}|null
     */
    private function dryRunLoans(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $preview = $this->preview->previewLoans($path);

        return [
            'created' => $preview['row_count'] ?? 0,
            'failed' => count($preview['missing_columns'] ?? []),
            'errors' => array_merge($preview['warnings'] ?? [], $preview['missing_columns'] ?? []),
        ];
    }

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     members_path?: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     *     strategy?: 'snapshot'|'historical'
     * }  $options
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}|null
     */
    private function dryRunPayments(array $options): ?array
    {
        $strategy = $options['strategy'] ?? 'snapshot';

        if ($strategy !== 'historical') {
            return null;
        }

        $classifiedPath = $this->resolveClassifiedPaymentsPath($options);
        $paymentsPath = $options['payments_path'] ?? null;

        if (is_string($paymentsPath) && $paymentsPath !== '' && is_readable($paymentsPath)) {
            $membersPath = $options['members_path'] ?? null;

            if (! filled($membersPath) || ! is_readable($membersPath)) {
                $preview = $this->preview->previewPayments($paymentsPath);

                return [
                    'contributions' => $preview['row_count'] ?? 0,
                    'loan_repayments' => 0,
                    'ignored' => 0,
                    'failed' => count($preview['missing_columns'] ?? []),
                    'errors' => array_merge(
                        $preview['missing_columns'] ?? [],
                        [__('Classify payments first, or upload members CSV so dry run can classify payment rows.')],
                    ),
                ];
            }

            $cutoff = filled($options['cutoff_date'] ?? null)
                ? Carbon::parse((string) $options['cutoff_date'])
                : null;

            $result = $this->classifier->classifyFile(
                $paymentsPath,
                $cutoff,
                $membersPath,
                $options['loans_path'] ?? null,
                $this->resolveGraceCycles($options),
            );

            return [
                'contributions' => $result['stats']['contribution'],
                'loan_repayments' => $result['stats']['loan_repayment'],
                'ignored' => $result['stats']['ignore'],
                'failed' => ($result['stats']['unclassified'] ?? 0) + ($result['stats']['failed'] ?? 0),
                'errors' => array_slice($result['errors'] ?? [], 0, 10),
            ];
        }

        if ($classifiedPath !== null) {
            $summary = $this->classifier->summarizeClassifiedFile($classifiedPath);

            return [
                ...$summary,
                'errors' => [],
            ];
        }

        return null;
    }

    /**
     * @param  array{
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     */
    private function resolvePaymentsImportPath(array $options): ?string
    {
        $classifiedPath = $this->resolveClassifiedPaymentsPath($options);

        if ($classifiedPath !== null) {
            return $classifiedPath;
        }

        $uploadedPath = $options['payments_path'] ?? null;

        return filled($uploadedPath) ? (string) $uploadedPath : null;
    }

    /**
     * @param  array{classified_payments_path?: string|null}  $options
     */
    private function resolveClassifiedPaymentsPath(array $options): ?string
    {
        $explicit = $options['classified_payments_path'] ?? null;

        if (filled($explicit) && is_readable($explicit)) {
            return $explicit;
        }

        $default = LegacyPaymentClassifierService::classifiedPaymentsAbsolutePath();

        return ($default !== null && is_readable($default)) ? $default : null;
    }

    /**
     * @param  array{
     *     strategy?: 'snapshot'|'historical',
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     */
    public function shouldQueuePaymentImport(array $options): bool
    {
        return ($options['strategy'] ?? 'snapshot') === 'historical'
            && $this->resolvePaymentsImportPath($options) !== null;
    }

    /**
     * @param  array{grace_cycles?: int|string|null}  $options
     */
    private function resolveGraceCycles(array $options): int
    {
        if (array_key_exists('grace_cycles', $options) && $options['grace_cycles'] !== null && $options['grace_cycles'] !== '') {
            return max(0, min(2, (int) $options['grace_cycles']));
        }

        return LegacyMigrationGraceCycleSettings::graceCycles();
    }

    /**
     * @param  array{loan_funding_strategy?: string|null}  $options
     */
    private function resolveFundingStrategy(array $options): string
    {
        if (
            array_key_exists('loan_funding_strategy', $options)
            && is_string($options['loan_funding_strategy'])
            && $options['loan_funding_strategy'] !== ''
        ) {
            return LoanFundingStrategy::normalize($options['loan_funding_strategy']);
        }

        return LegacyMigrationFundingStrategySettings::fundingStrategy();
    }

    /**
     * @param  array{skip_settlement_threshold?: bool|string|int|null}  $options
     */
    private function resolveSkipSettlementThreshold(array $options): bool
    {
        if (array_key_exists('skip_settlement_threshold', $options) && $options['skip_settlement_threshold'] !== null) {
            return filter_var($options['skip_settlement_threshold'], FILTER_VALIDATE_BOOLEAN);
        }

        return LegacyMigrationSettlementThresholdSettings::skipSettlementThreshold();
    }

    /**
     * @param  array{
     *     strategy?: 'snapshot'|'historical',
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     */
    private function resolvePaymentsPathForLoanFunding(array $options): ?string
    {
        if (($options['strategy'] ?? 'snapshot') !== 'historical') {
            return null;
        }

        $paymentsPath = $options['payments_path'] ?? null;

        return filled($paymentsPath) && is_readable($paymentsPath) ? (string) $paymentsPath : null;
    }

    private function repairStrayRepaymentsOnFundOnlyLoans(): void
    {
        Loan::query()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->each(function (Loan $loan): void {
                if (LegacyLoanRepaymentTarget::forLoan($loan) > 0.01) {
                    return;
                }

                if (! $loan->repayments()->exists()) {
                    return;
                }

                $this->excessRepaymentRepair->repairLoan($loan);
            });
    }
}
