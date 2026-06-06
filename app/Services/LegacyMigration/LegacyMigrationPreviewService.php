<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Support\AssociativeCsv;

final class LegacyMigrationPreviewService
{
    /**
     * @return array{
     *     row_count: int,
     *     headers: list<string>,
     *     missing_columns: list<string>,
     *     warnings: list<string>
     * }
     */
    public function previewMembers(?string $absolutePath): ?array
    {
        if ($absolutePath === null || $absolutePath === '') {
            return null;
        }

        return $this->preview(
            $absolutePath,
            ['name', 'email'],
            [
                'cutoff_cash_balance' => __('No cut-off cash balances detected — members will start at zero cash unless you add cutoff_cash_balance or opening_cash_balance.'),
                'cutoff_fund_balance' => __('No cut-off fund balances detected — members will start at zero fund unless you add cutoff_fund_balance or opening_fund_balance.'),
            ],
        );
    }

    /**
     * @return array{
     *     row_count: int,
     *     headers: list<string>,
     *     missing_columns: list<string>,
     *     warnings: list<string>
     * }|null
     */
    public function previewLoans(?string $absolutePath): ?array
    {
        if ($absolutePath === null || $absolutePath === '') {
            return null;
        }

        $preview = $this->preview(
            $absolutePath,
            [],
            [],
        );

        $headers = $preview['headers'];
        $memberKeys = ['member_email', 'member_number', 'national_id', 'member_name', 'name'];
        $hasMember = array_intersect($memberKeys, $headers) !== [];

        if (!$hasMember) {
            $preview['missing_columns'][] = 'member_email|member_number|national_id|member_name';
        }

        if (!in_array('amount_approved', $headers, true) && !in_array('amount_requested', $headers, true)) {
            $preview['missing_columns'][] = 'amount_approved';
        }

        return $preview;
    }

    /**
     * @return array{
     *     row_count: int,
     *     headers: list<string>,
     *     missing_columns: list<string>,
     *     warnings: list<string>
     * }|null
     */
    public function previewPayments(?string $absolutePath): ?array
    {
        if ($absolutePath === null || $absolutePath === '') {
            return null;
        }

        $preview = $this->preview(
            $absolutePath,
            ['payment_date', 'amount'],
            [],
        );

        $headers = $preview['headers'];
        $memberKeys = ['member_email', 'member_number', 'national_id', 'member_name', 'name'];
        $hasMember = array_intersect($memberKeys, $headers) !== [];

        if (!$hasMember) {
            $preview['missing_columns'][] = 'member_email|member_number|national_id|member_name';
        }

        return $preview;
    }

    /**
     * @param  list<string>  $requiredColumns
     * @param  array<string, string>  $optionalWarnings
     * @return array{
     *     row_count: int,
     *     headers: list<string>,
     *     missing_columns: list<string>,
     *     warnings: list<string>
     * }
     */
    private function preview(string $absolutePath, array $requiredColumns, array $optionalWarnings): array
    {
        $headers = AssociativeCsv::headers($absolutePath);
        $rows = AssociativeCsv::read($absolutePath);

        $missing = array_values(array_filter(
            $requiredColumns,
            fn(string $column): bool => !in_array($column, $headers, true),
        ));

        $warnings = [];

        foreach ($optionalWarnings as $column => $message) {
            $aliases = match ($column) {
                'cutoff_cash_balance' => ['cutoff_cash_balance', 'opening_cash_balance', 'cut_off_cash_balance'],
                'cutoff_fund_balance' => ['cutoff_fund_balance', 'opening_fund_balance', 'cut_off_fund_balance'],
                default => [$column],
            };

            if (array_intersect($aliases, $headers) === []) {
                $warnings[] = $message;
            }
        }

        return [
            'row_count' => count($rows),
            'headers' => $headers,
            'missing_columns' => $missing,
            'warnings' => $warnings,
        ];
    }
}
