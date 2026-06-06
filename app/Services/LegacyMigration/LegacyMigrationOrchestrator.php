<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Services\Loans\LoanImportService;
use App\Services\MemberImportService;
use Carbon\Carbon;

final class LegacyMigrationOrchestrator
{
    public function __construct(
        private readonly MemberImportService $members,
        private readonly LoanImportService $loans,
        private readonly LegacyPaymentImportService $payments,
        private readonly LegacyMigrationPreviewService $preview,
    ) {
    }

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path?: string|null,
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
        $strategy = $options['strategy'] ?? 'snapshot';
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
            return [
                'members' => [
                    'created' => $memberPreview['row_count'],
                    'skipped' => 0,
                    'failed' => 0,
                    'errors' => $memberPreview['warnings'],
                ],
                'loans' => $this->dryRunLoans($options['loans_path'] ?? null),
                'payments' => $this->dryRunPayments($options['payments_path'] ?? null, $strategy),
            ];
        }

        $result = [
            'members' => $this->members->import(
                $options['members_path'],
                $options['default_password'],
                $cutoff,
            ),
        ];

        if (filled($options['loans_path'] ?? null)) {
            $result['loans'] = $this->loans->import((string) $options['loans_path']);
        }

        if ($strategy === 'historical' && filled($options['payments_path'] ?? null)) {
            $result['payments'] = $this->payments->import((string) $options['payments_path']);
        }

        return $result;
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
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}|null
     */
    private function dryRunPayments(?string $path, string $strategy): ?array
    {
        if ($strategy !== 'historical' || $path === null || $path === '') {
            return null;
        }

        $preview = $this->preview->previewPayments($path);

        return [
            'contributions' => $preview['row_count'] ?? 0,
            'loan_repayments' => 0,
            'ignored' => 0,
            'failed' => count($preview['missing_columns'] ?? []),
            'errors' => $preview['missing_columns'] ?? [],
        ];
    }
}
