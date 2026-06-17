<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\Loans\LoanImportService;
use App\Services\MemberImportService;
use App\Support\AssociativeCsv;
use Carbon\Carbon;

final class LegacyMigrationOrchestrator
{
    public function __construct(
        private readonly MemberImportService $members,
        private readonly LoanImportService $loans,
        private readonly LegacyPaymentImportService $payments,
        private readonly LegacyMigrationPreviewService $preview,
        private readonly LegacyPaymentClassifierService $classifier,
        private readonly AccountingService $accounting,
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
    public function importMembersAndLoans(array $options, ?string $cutoff = null): array
    {
        $this->validateMembersCsv($options['members_path']);

        $cutoff ??= filled($options['cutoff_date'] ?? null)
            ? Carbon::parse((string) $options['cutoff_date'])->toDateString()
            : null;

        $result = [
            'members' => $this->members->import(
                $options['members_path'],
                $options['default_password'],
                $cutoff,
            ),
        ];

        $this->assertAllCsvMembersPresent((string) $options['members_path'], $result['members']);

        if (filled($options['loans_path'] ?? null)) {
            $result['loans'] = $this->loans->import((string) $options['loans_path']);
        }

        return $result;
    }

    /**
     * @param  array{
     *     strategy?: 'snapshot'|'historical',
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}|null
     */
    public function importPayments(array $options): ?array
    {
        if (($options['strategy'] ?? 'snapshot') !== 'historical') {
            return null;
        }

        $paymentsPath = $this->resolvePaymentsImportPath($options);

        if ($paymentsPath === null) {
            return null;
        }

        return ContributionService::withoutPostedNotifications(
            fn (): array => $this->payments->import($paymentsPath),
        );
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
        Account::query()
            ->whereHas('transactions')
            ->orderBy('id')
            ->eachById(function (Account $account): void {
                $this->accounting->reconcileAccountLedgerBalances($account);
            });
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

        if ($classifiedPath !== null) {
            $summary = $this->classifier->summarizeClassifiedFile($classifiedPath);

            return [
                ...$summary,
                'errors' => [],
            ];
        }

        $path = $options['payments_path'] ?? null;

        if ($path === null || $path === '') {
            return null;
        }

        $membersPath = $options['members_path'] ?? null;

        if (filled($membersPath) && is_readable($membersPath)) {
            $cutoff = filled($options['cutoff_date'] ?? null)
                ? Carbon::parse((string) $options['cutoff_date'])
                : null;

            $result = $this->classifier->classifyFile(
                $path,
                $cutoff,
                $membersPath,
                $options['loans_path'] ?? null,
            );

            return [
                'contributions' => $result['stats']['contribution'],
                'loan_repayments' => $result['stats']['loan_repayment'],
                'ignored' => $result['stats']['ignore'],
                'failed' => ($result['stats']['unclassified'] ?? 0) + ($result['stats']['failed'] ?? 0),
                'errors' => array_slice($result['errors'] ?? [], 0, 10),
            ];
        }

        $preview = $this->preview->previewPayments($path);

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
}
