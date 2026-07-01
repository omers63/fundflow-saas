<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class ReconciliationSnapshotPresenter
{
    /**
     * @return array{label: string, badge: string, text: string, ring: string}
     */
    public static function severityStyle(string $severity): array
    {
        return match (strtolower($severity)) {
            'critical' => [
                'label' => __('Critical'),
                'badge' => 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
                'text' => 'text-red-700 dark:text-red-300',
                'ring' => 'border-red-200 dark:border-red-500/30',
            ],
            'warning' => [
                'label' => __('Warning'),
                'badge' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
                'text' => 'text-amber-800 dark:text-amber-300',
                'ring' => 'border-amber-200 dark:border-amber-500/30',
            ],
            'ok', 'pass', 'success' => [
                'label' => __('Pass'),
                'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
                'text' => 'text-emerald-700 dark:text-emerald-300',
                'ring' => 'border-emerald-200 dark:border-emerald-500/30',
            ],
            'skipped' => [
                'label' => __('Skipped'),
                'badge' => 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300',
                'text' => 'text-gray-600 dark:text-gray-400',
                'ring' => 'border-gray-200 dark:border-white/10',
            ],
            default => [
                'label' => ucfirst($severity),
                'badge' => 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300',
                'text' => 'text-gray-700 dark:text-gray-300',
                'ring' => 'border-gray-200 dark:border-white/10',
            ],
        };
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            ReconciliationSnapshot::MODE_REALTIME => __('Real-time'),
            ReconciliationSnapshot::MODE_DAILY => __('Daily'),
            ReconciliationSnapshot::MODE_MONTHLY => __('Monthly'),
            default => ucfirst($mode),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @return list<array{key: string, check: array<string, mixed>, rank: int}>
     */
    public static function orderedChecks(array $checks): array
    {
        $rank = static fn (string $severity): int => match (strtolower($severity)) {
            'critical' => 0,
            'warning' => 1,
            'fail' => 2,
            'ok', 'pass', 'success' => 3,
            'skipped' => 4,
            default => 5,
        };

        $rows = [];

        foreach ($checks as $key => $check) {
            $rows[] = [
                'key' => (string) $key,
                'check' => $check,
                'rank' => $rank((string) ($check['severity'] ?? 'unknown')),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['rank'] <=> $b['rank'] ?: strcmp($a['key'], $b['key']));

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public static function checkHeadline(string $key, array $check): string
    {
        if (filled($check['label'] ?? null)) {
            return (string) $check['label'];
        }

        return str($key)->replace('_', ' ')->headline()->toString();
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public static function checkSummary(string $key, array $check, ?string $currency = null): ?string
    {
        $currency ??= self::currency();

        return match ($key) {
            'ledger_balances' => __(':count account(s) out of :checked', [
                'count' => number_format((int) ($check['mismatch_count'] ?? 0)),
                'checked' => number_format((int) ($check['accounts_checked'] ?? 0)),
            ]),
            'global_trial' => __('Credits :credits · Debits :debits · Δ :delta', [
                'credits' => MoneyDisplay::format((float) ($check['sum_credits'] ?? 0), $currency) ?? '—',
                'debits' => MoneyDisplay::format((float) ($check['sum_debits'] ?? 0), $currency) ?? '—',
                'delta' => MoneyDisplay::format((float) ($check['delta'] ?? 0), $currency) ?? '—',
            ]),
            'paired_control_totals' => __('Cash Δ :cash · Fund pool Δ :fund', [
                'cash' => MoneyDisplay::format((float) ($check['cash_delta_abs'] ?? abs((float) ($check['cash_delta'] ?? 0))), $currency) ?? '—',
                'fund' => MoneyDisplay::format((float) ($check['fund_delta_abs'] ?? abs((float) ($check['fund_delta'] ?? 0))), $currency) ?? '—',
            ]),
            'bank_statement_vs_book' => isset($check['declared_balance'])
                ? __('Book :book vs stated :stated · variance :variance', [
                    'book' => MoneyDisplay::format((float) ($check['master_cash_book'] ?? 0), $currency) ?? '—',
                    'stated' => MoneyDisplay::format((float) $check['declared_balance'], $currency) ?? '—',
                    'variance' => MoneyDisplay::format((float) ($check['variance_book_minus_stated'] ?? 0), $currency) ?? '—',
                ])
                : null,
            'contributions_ledger' => __('Missing ledger rows: :missing · Master fund Δ :delta', [
                'missing' => number_format((int) ($check['missing_ledger_count'] ?? 0)),
                'delta' => MoneyDisplay::format((float) ($check['master_fund_delta'] ?? 0), $currency) ?? '—',
            ]),
            'active_loans_schedule_vs_ledger', 'approved_loans_disbursement_vs_ledger', 'loan_disbursement_cash_payout_integrity' => trans_choice(
                ':count loan mismatch|:count loan mismatches',
                (int) ($check['mismatch_count'] ?? $check['issue_count'] ?? 0),
                ['count' => number_format((int) ($check['mismatch_count'] ?? $check['issue_count'] ?? 0))],
            ),
            'bank_transaction_posting_integrity', 'member_portal_posting_integrity', 'contribution_flow_integrity',
            'membership_application_fee_integrity', 'subscription_fee_integrity', 'loan_installment_flow_integrity',
            'member_cash_transfer_integrity', 'orphan_loan_accounts' => trans_choice(
                ':count issue|:count issues',
                (int) ($check['issue_count'] ?? $check['mismatch_count'] ?? 0),
                ['count' => number_format((int) ($check['issue_count'] ?? $check['mismatch_count'] ?? 0))],
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $check
     * @return list<array{title: string, rows: list<array<string, scalar|null>>, truncated: bool}>
     */
    public static function checkDetailSections(string $key, array $check): array
    {
        $sections = [];

        foreach (['mismatches', 'issues', 'missing_ledger_sample'] as $bucket) {
            $rows = $check[$bucket] ?? null;

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $sections[] = [
                'title' => match ($bucket) {
                    'mismatches' => __('Mismatch details'),
                    'issues' => __('Issue details'),
                    'missing_ledger_sample' => __('Missing ledger rows (sample)'),
                    default => __('Details'),
                },
                'format' => 'table',
                'rows' => array_map(
                    fn (mixed $row): array => is_array($row) ? self::normalizeDetailRow($row) : ['value' => (string) $row],
                    array_values($rows),
                ),
                'truncated' => (bool) ($check[$bucket.'_truncated'] ?? false),
            ];
        }

        $metrics = self::checkMetricRows($key, $check);

        if ($metrics !== []) {
            array_unshift($sections, [
                'title' => __('Metrics'),
                'format' => 'metrics',
                'rows' => $metrics,
                'truncated' => false,
            ]);
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $check
     * @return list<array<string, scalar|null>>
     */
    public static function checkMetricRows(string $key, array $check): array
    {
        $currency = self::currency();
        $money = static fn (mixed $value): ?string => is_numeric($value)
            ? MoneyDisplay::format((float) $value, $currency)
            : null;

        $skip = [
            'label', 'severity', 'note', 'mismatches', 'issues', 'missing_ledger_sample',
            'mismatches_truncated', 'issues_truncated',
        ];

        $rows = [];

        foreach ($check as $field => $value) {
            if (in_array($field, $skip, true) || is_array($value)) {
                continue;
            }

            $label = str((string) $field)->replace('_', ' ')->headline()->toString();
            $formatted = is_numeric($value) && str_contains((string) $field, 'amount')
                || str_contains((string) $field, 'balance')
                || str_contains((string) $field, 'delta')
                || str_contains((string) $field, 'credits')
                || str_contains((string) $field, 'debits')
                || str_ends_with((string) $field, '_sum')
                ? $money($value)
                : (is_bool($value) ? ($value ? __('Yes') : __('No')) : (string) $value);

            $rows[] = [$label => $formatted];
        }

        if (filled($check['note'] ?? null) && ! in_array($key, ['bank_statement_vs_book'], true)) {
            $rows[] = [__('Note') => (string) $check['note']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, scalar|null>
     */
    public static function normalizeDetailRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $field => $value) {
            if (is_array($value)) {
                $normalized[(string) $field] = json_encode($value, JSON_UNESCAPED_UNICODE);

                continue;
            }

            $normalized[(string) $field] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    public static function detailCellLabel(string $field): string
    {
        return match ($field) {
            'loan_id' => __('Loan'),
            'member_id' => __('Member'),
            'account_id' => __('Account'),
            'contribution_id' => __('Contribution'),
            'bank_transaction_id' => __('Bank line'),
            'delta' => __('Δ'),
            'ledger_outstanding' => __('Ledger outstanding'),
            'ledger_expected', 'expected_outstanding' => __('Expected'),
            'scheduled_outstanding' => __('Scheduled'),
            'partial_paid_ahead' => __('Partial paid'),
            'stored_balance' => __('Stored'),
            'computed_from_ledger' => __('From ledger'),
            'issue' => __('Issue'),
            'member' => __('Member'),
            'name' => __('Account'),
            'type' => __('Type'),
            'period' => __('Period'),
            'amount' => __('Amount'),
            default => str($field)->replace('_', ' ')->headline()->toString(),
        };
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    public static function detailCellValue(string $field, mixed $value, ?string $currency = null): Htmlable|string|null
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $currency ??= self::currency();

        if (is_numeric($value) && (
            str_contains($field, 'amount')
            || str_contains($field, 'balance')
            || str_contains($field, 'outstanding')
            || str_contains($field, 'delta')
            || $field === 'delta'
        )) {
            return new HtmlString(MoneyDisplay::html((float) $value, $currency)?->toHtml() ?? (string) $value);
        }

        return match ($field) {
            'loan_id' => self::loanLink((int) $value),
            'member_id' => self::memberLink((int) $value),
            'account_id' => self::accountLink((int) $value),
            'contribution_id' => self::contributionLink((int) $value),
            'bank_transaction_id' => self::bankLineLink((int) $value),
            default => (string) $value,
        };
    }

    public static function loanLink(int $loanId): Htmlable
    {
        $url = LoanResource::getUrl('view', ['record' => $loanId]);

        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">#'
            .e((string) $loanId).'</a>'
        );
    }

    public static function memberLink(int $memberId): Htmlable
    {
        $url = MemberResource::getUrl('view', ['record' => $memberId]);

        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">#'
            .e((string) $memberId).'</a>'
        );
    }

    public static function accountLink(int $accountId): Htmlable
    {
        $url = AccountResource::getUrl('view', ['record' => $accountId]);

        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">#'
            .e((string) $accountId).'</a>'
        );
    }

    public static function contributionLink(int $contributionId): Htmlable
    {
        $url = ContributionResource::getUrl('edit', ['record' => $contributionId]);

        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">#'
            .e((string) $contributionId).'</a>'
        );
    }

    public static function bankLineLink(int $bankTransactionId): Htmlable
    {
        $url = BankAccountsResource::getUrl('index', [
            'activeTab' => BankClearingTabRegistry::TAB_QUEUE,
        ]);

        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">#'
            .e((string) $bankTransactionId).'</a>'
        );
    }

    public static function currency(): string
    {
        return (string) Setting::get('general', 'currency', 'USD');
    }
}
