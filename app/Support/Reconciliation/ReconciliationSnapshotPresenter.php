<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
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
            'global_trial' => isset($check['unbalanced_posting_group_count']) && (int) $check['unbalanced_posting_group_count'] > 0
            ? __('Credits :credits · Debits :debits · Δ :delta · :count suspected posting group(s)', [
                'credits' => MoneyDisplay::format((float) ($check['sum_credits'] ?? 0), $currency) ?? '—',
                'debits' => MoneyDisplay::format((float) ($check['sum_debits'] ?? 0), $currency) ?? '—',
                'delta' => MoneyDisplay::format((float) ($check['delta'] ?? 0), $currency) ?? '—',
                'count' => number_format((int) $check['unbalanced_posting_group_count']),
            ])
            : __('Credits :credits · Debits :debits · Δ :delta', [
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
            'bank_pipeline' => __(':unposted unposted · :uncleared uncleared', [
                'unposted' => number_format((int) ($check['bank_unposted_count'] ?? 0)),
                'uncleared' => number_format((int) ($check['bank_uncleared_count'] ?? 0)),
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

        if (filled($check['resolution_hints'] ?? null) && is_array($check['resolution_hints'])) {
            $sections[] = [
                'title' => __('How to investigate'),
                'format' => 'hints',
                'rows' => array_map(
                    fn (mixed $hint): array => ['hint' => (string) $hint],
                    array_values($check['resolution_hints']),
                ),
                'truncated' => false,
            ];
        }

        foreach ([
            'mismatches',
            'issues',
            'missing_ledger_sample',
            'suspected_postings',
            'suspected_posting_lines',
            'null_reference_lines',
            'cash_mirror_mismatches',
            'fund_mirror_mismatches',
            'fund_pool_adjustments',
            'cash_related_transactions',
            'fund_related_transactions',
            'net_by_account_type',
        ] as $bucket) {
            $rows = $check[$bucket] ?? null;

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $displayRows = match ($bucket) {
                'suspected_postings' => self::enrichSuspectedPostingRows($rows),
                'suspected_posting_lines' => self::enrichPostingGroupLineRows($rows),
                'null_reference_lines' => self::enrichNullReferenceRows($rows),
                'cash_mirror_mismatches', 'fund_mirror_mismatches' => self::enrichPoolMirrorMismatchRows($rows),
                'cash_related_transactions', 'fund_related_transactions', 'fund_pool_adjustments' => self::enrichPoolRelatedTransactionRows($rows),
                default => array_map(
                    fn (mixed $row): array => is_array($row) ? self::normalizeDetailRow($row) : ['value' => (string) $row],
                    array_values($rows),
                ),
            };

            $sections[] = [
                'title' => match ($bucket) {
                    'mismatches' => __('Mismatch details'),
                    'issues' => __('Issue details'),
                    'missing_ledger_sample' => __('Missing ledger rows (sample)'),
                    'suspected_postings' => __('Suspected unbalanced postings'),
                    'suspected_posting_lines' => __('Suspected posting lines'),
                    'null_reference_lines' => __('Null-reference ledger lines'),
                    'cash_mirror_mismatches' => __('Cash mirror mismatch groups'),
                    'fund_mirror_mismatches' => __('Fund mirror mismatch groups'),
                    'fund_pool_adjustments' => __('Fund pool adjustments'),
                    'cash_related_transactions' => __('Cash related transactions'),
                    'fund_related_transactions' => __('Fund related transactions'),
                    'net_by_account_type' => $key === 'global_trial'
                        ? __('Net movement by account bucket')
                        : __('Trial drift by account type'),
                    default => __('Details'),
                },
                'format' => 'table',
                'table_align' => $bucket === 'net_by_account_type' ? 'center' : 'start',
                'rows' => $displayRows,
                'truncated' => (bool) ($check[$bucket.'_truncated'] ?? false),
                'collapsible' => in_array($bucket, [
                    'suspected_postings',
                    'suspected_posting_lines',
                    'null_reference_lines',
                    'cash_mirror_mismatches',
                    'fund_mirror_mismatches',
                    'fund_pool_adjustments',
                    'cash_related_transactions',
                    'fund_related_transactions',
                ], true),
                'default_open' => false,
            ];
        }

        $metrics = self::checkMetricRows($key, $check);

        if ($metrics !== []) {
            array_unshift($sections, [
                'title' => $key === 'global_trial'
                    ? __('Global totals and diagnostic counts')
                    : __('Metrics'),
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

        if ($key === 'global_trial') {
            return self::globalTrialMetricRows($check, $currency);
        }

        if ($key === 'paired_control_totals') {
            return self::pairedControlMetricRows($check, $currency);
        }

        $skip = [
            'label', 'severity', 'note', 'mismatches', 'issues', 'missing_ledger_sample',
            'suspected_postings',
            'suspected_posting_lines',
            'null_reference_lines',
            'cash_mirror_mismatches',
            'fund_mirror_mismatches',
            'fund_pool_adjustments',
            'cash_related_transactions',
            'fund_related_transactions',
            'net_by_account_type',
            'resolution_hints',
            'mismatches_truncated',
            'issues_truncated',
            'suspected_postings_truncated',
            'suspected_posting_lines_truncated',
            'null_reference_lines_truncated',
            'cash_mirror_mismatches_truncated',
            'fund_mirror_mismatches_truncated',
            'fund_pool_adjustments_truncated',
            'cash_related_transactions_truncated',
            'fund_related_transactions_truncated',
        ];

        $rows = [];

        foreach ($check as $field => $value) {
            if (in_array($field, $skip, true) || is_array($value)) {
                continue;
            }

            $label = self::detailCellLabel($field);
            if ($label === str($field)->replace('_', ' ')->headline()->toString() && str_ends_with((string) $field, '_count')) {
                $label = str((string) $field)->beforeLast('_count')->replace('_', ' ')->headline()->append(' '.__('count'))->toString();
            }

            $formatted = self::formatMetricValue((string) $field, $value, $currency);

            $rows[] = [$label => $formatted];
        }

        if (filled($check['note'] ?? null) && ! in_array($key, ['bank_statement_vs_book'], true)) {
            $rows[] = [__('Note') => (string) $check['note']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $check
     * @return list<array<string, scalar|null>>
     */
    private static function globalTrialMetricRows(array $check, string $currency): array
    {
        $orderedFields = [
            'sum_credits',
            'sum_debits',
            'delta',
            'unbalanced_posting_group_count',
            'null_reference_line_count',
            'null_reference_credits',
            'null_reference_debits',
            'null_reference_delta',
        ];

        $rows = [];

        foreach ($orderedFields as $field) {
            if (! array_key_exists($field, $check)) {
                continue;
            }

            $rows[] = [
                self::detailCellLabel($field) => self::formatMetricValue($field, $check[$field], $currency),
            ];
        }

        $rows[] = [
            __('How to read these metrics') => __(
                'These figures are book-wide aggregates across all ledger lines in this snapshot. They are not totals for one linked source or one posting group.',
            ),
        ];

        if (filled($check['note'] ?? null)) {
            $rows[] = [__('Note') => (string) $check['note']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $check
     * @return list<array<string, scalar|null>>
     */
    private static function pairedControlMetricRows(array $check, string $currency): array
    {
        $orderedFields = [
            'tolerance',
            'master_cash_balance',
            'sum_member_cash',
            'cash_delta',
            'cash_delta_abs',
            'master_fund_balance',
            'master_invest_from_fund_credits',
            'master_expense_from_fund_credits',
            'master_invest_return_to_fund_credits',
            'master_fund_pool',
            'sum_member_fund',
            'fund_delta',
            'fund_delta_abs',
            'master_invest_balance',
            'master_expense_balance',
            'master_fees_balance',
            'master_suspense_balance',
            'master_bank_balance',
        ];

        $rows = [];

        foreach ($orderedFields as $field) {
            if (! array_key_exists($field, $check)) {
                continue;
            }

            $rows[] = [
                self::detailCellLabel($field) => self::formatMetricValue($field, $check[$field], $currency),
            ];
        }

        if (filled($check['note'] ?? null)) {
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
            'posting' => __('Posting'),
            'sum_credits' => __('Σ credits Flow'),
            'sum_debits' => __('Σ debits Flow'),
            'posting_delta' => __('Posting Δ'),
            'line_count' => __('Lines'),
            'sample_description' => __('Description'),
            'first_transacted_at' => __('First posted'),
            'account_type' => __('Account type'),
            'scope' => __('Scope'),
            'net_delta' => __('Net Δ'),
            'null_reference_line_count' => __('Null-reference lines'),
            'null_reference_credits' => __('Null-reference credits'),
            'null_reference_debits' => __('Null-reference debits'),
            'null_reference_delta' => __('Null-reference Δ'),
            'unbalanced_posting_group_count' => __('Unbalanced posting groups'),
            'transaction_id' => __('Transaction'),
            'account_scope' => __('Scope'),
            'tolerance' => __('Tolerance'),
            'master_cash_balance' => __('Master cash balance'),
            'sum_member_cash' => __('Sum member cash'),
            'cash_delta' => __('Cash delta'),
            'cash_delta_abs' => __('Absolute cash delta'),
            'master_fund_balance' => __('Master fund balance'),
            'master_fund_pool' => __('Adjusted master fund pool'),
            'sum_member_fund' => __('Sum member fund'),
            'fund_delta' => __('Fund pool delta'),
            'fund_delta_abs' => __('Absolute fund pool delta'),
            'master_invest_from_fund_credits' => __('Reserve funding to invest'),
            'master_expense_from_fund_credits' => __('Reserve funding to expense'),
            'master_invest_return_to_fund_credits' => __('Invest return credited to fund'),
            'master_invest_balance' => __('Master invest balance'),
            'master_expense_balance' => __('Master expense balance'),
            'master_fees_balance' => __('Master fees balance'),
            'master_suspense_balance' => __('Master suspense balance'),
            'master_bank_balance' => __('Master bank balance'),
            'reference' => __('Linked source'),
            'master_amount' => __('Master amount'),
            'member_amount' => __('Member amount'),
            'mirror_delta' => __('Mirror Δ'),
            'master_lines' => __('Master lines'),
            'member_lines' => __('Member lines'),
            'last_transacted_at' => __('Last posted'),
            'linked_source' => __('Linked source'),
            'adjustment_kind' => __('Adjustment'),
            default => str($field)->replace('_', ' ')->headline()->toString(),
        };
    }

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
                || str_contains($field, 'credits')
                || str_contains($field, 'debits')
            || $field === 'delta'
        )) {
            return new HtmlString(MoneyDisplay::html((float) $value, $currency)?->toHtml() ?? (string) $value);
        }

        if (in_array($field, ['posting', 'reference'], true) && $value instanceof Htmlable) {
            return $value;
        }

        return match ($field) {
            'loan_id' => self::loanLink((int) $value),
            'member_id' => self::memberLink((int) $value),
            'account_id' => self::accountLink((int) $value),
            'contribution_id' => self::contributionLink((int) $value),
            'bank_transaction_id' => self::bankLineLink((int) $value),
            'transaction_id' => self::ledgerTransactionLink((int) $value),
            'account_scope' => match ((string) $value) {
                'master' => __('Master'),
                'member' => __('Member'),
                default => (string) $value,
            },
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
        $account = Account::query()->find($accountId);

        $url = match (true) {
            $account?->is_master === true => MasterAccountResource::getUrl('view', ['record' => $accountId]),
            default => AccountResource::getUrl('view', ['record' => $accountId]),
        };

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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function enrichSuspectedPostingRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $referenceType = (string) ($row['reference_type'] ?? '');
            $referenceId = (int) ($row['reference_id'] ?? 0);

            return [
                'reference' => self::referenceLink($referenceType, $referenceId),
                'sum_credits' => $row['sum_credits'] ?? null,
                'sum_debits' => $row['sum_debits'] ?? null,
                'posting_delta' => $row['posting_delta'] ?? null,
                'line_count' => $row['line_count'] ?? null,
                'sample_description' => $row['sample_description'] ?? null,
                'first_transacted_at' => $row['first_transacted_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public static function enrichPostingGroupLineRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $referenceType = (string) ($row['reference_type'] ?? '');
            $referenceId = (int) ($row['reference_id'] ?? 0);
            $transactionId = (int) ($row['transaction_id'] ?? 0);
            $type = (string) ($row['type'] ?? '');

            return [
                'reference' => self::referenceLink($referenceType, $referenceId),
                'transaction_id' => $transactionId,
                'transacted_at' => $row['transacted_at'] ?? null,
                'type' => Transaction::typeLabel($type !== '' ? $type : null),
                'amount' => $row['amount'] ?? null,
                'account_id' => $row['account_id'] ?? null,
                'account_type' => $row['account_type'] ?? null,
                'account_scope' => $row['account_scope'] ?? null,
                'member' => $row['member'] ?? null,
                'description' => $row['description'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public static function enrichNullReferenceRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $transactionId = (int) ($row['transaction_id'] ?? 0);
            $type = (string) ($row['type'] ?? '');

            return [
                'transaction_id' => $transactionId,
                'transacted_at' => $row['transacted_at'] ?? null,
                'type' => Transaction::typeLabel($type !== '' ? $type : null),
                'amount' => $row['amount'] ?? null,
                'account_id' => $row['account_id'] ?? null,
                'account_type' => $row['account_type'] ?? null,
                'account_scope' => $row['account_scope'] ?? null,
                'member' => $row['member'] ?? null,
                'description' => $row['description'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public static function enrichPoolMirrorMismatchRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $referenceType = (string) ($row['reference_type'] ?? '');
            $referenceId = (int) ($row['reference_id'] ?? 0);

            return [
                'reference' => self::referenceLink($referenceType, $referenceId),
                'master_amount' => $row['master_amount'] ?? null,
                'member_amount' => $row['member_amount'] ?? null,
                'mirror_delta' => $row['mirror_delta'] ?? null,
                'master_lines' => $row['master_lines'] ?? null,
                'member_lines' => $row['member_lines'] ?? null,
                'sample_description' => $row['sample_description'] ?? null,
                'last_transacted_at' => $row['last_transacted_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, scalar|null>>
     */
    public static function enrichPoolRelatedTransactionRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $transactionId = (int) ($row['transaction_id'] ?? 0);
            $type = (string) ($row['type'] ?? '');

            return [
                'transaction_id' => $transactionId,
                'transacted_at' => $row['transacted_at'] ?? null,
                'type' => Transaction::typeLabel($type !== '' ? $type : null),
                'amount' => $row['amount'] ?? null,
                'account_id' => $row['account_id'] ?? null,
                'account_type' => $row['account_type'] ?? null,
                'account_scope' => $row['account_scope'] ?? null,
                'member' => $row['member'] ?? null,
                'linked_source' => $row['linked_source'] ?? null,
                'adjustment_kind' => $row['adjustment_kind'] ?? null,
                'description' => $row['description'] ?? null,
            ];
        }, $rows);
    }

    public static function ledgerTransactionLink(int $transactionId): Htmlable|string
    {
        if ($transactionId <= 0) {
            return '—';
        }

        $label = __('Transaction #:id', ['id' => $transactionId]);
        $url = self::ledgerTransactionUrl($transactionId);

        if ($url === null) {
            return $label;
        }

        return self::linkHtml($label, $url);
    }

    public static function referenceLink(string $referenceType, int $referenceId): Htmlable|string
    {
        if ($referenceId <= 0 || blank($referenceType)) {
            return '—';
        }

        $label = class_basename($referenceType).' #'.$referenceId;
        $url = self::resolveReferenceUrl($referenceType, $referenceId);

        if ($url === null) {
            return $label;
        }

        return self::linkHtml($label, $url);
    }

    private static function resolveReferenceUrl(string $referenceType, int $referenceId): ?string
    {
        return match ($referenceType) {
            Contribution::class => self::safeResourceUrl(
                fn (): string => ContributionResource::getUrl('edit', ['record' => $referenceId]),
            ),
            Loan::class => self::safeResourceUrl(
                fn (): string => LoanResource::getUrl('view', ['record' => $referenceId]),
            ),
            LoanInstallment::class => self::loanUrlForChildRecord(
                LoanInstallment::query()->whereKey($referenceId)->value('loan_id'),
            ),
            LoanRepayment::class => self::loanUrlForChildRecord(
                LoanRepayment::query()->whereKey($referenceId)->value('loan_id'),
            ),
            FundPosting::class => self::fundPostingUrl($referenceId),
            Member::class => self::safeResourceUrl(
                fn (): string => MemberResource::getUrl('view', ['record' => $referenceId]),
            ),
            BankTransaction::class => self::safeResourceUrl(
                fn (): string => BankAccountsResource::getUrl('index', [
                    'activeTab' => BankClearingTabRegistry::TAB_QUEUE,
                ]),
            ),
            CashOutRequest::class => self::cashOutRequestUrl($referenceId),
            FeeDeduction::class => self::feeDeductionUrl($referenceId),
            InvestDisbursement::class, InvestReturn::class => self::masterAccountTypeUrl('invest'),
            ExpenseDisbursement::class => self::masterAccountTypeUrl('expense'),
            Transaction::class => self::ledgerTransactionUrl($referenceId),
            ReconciliationException::class => self::safeResourceUrl(
                fn (): string => ReconciliationExceptionResource::getUrl('index'),
            ),
            MembershipApplication::class => self::safeResourceUrl(
                fn (): string => MembershipApplicationResource::getUrl('edit', ['record' => $referenceId]),
            ),
            SmsTransaction::class => self::safeResourceUrl(
                fn (): string => SmsTransactionResource::getUrl('view', ['record' => $referenceId]),
            ),
            default => null,
        };
    }

    private static function loanUrlForChildRecord(mixed $loanId): ?string
    {
        if (! is_numeric($loanId) || (int) $loanId <= 0) {
            return null;
        }

        return self::safeResourceUrl(
            fn (): string => LoanResource::getUrl('view', ['record' => (int) $loanId]),
        );
    }

    private static function fundPostingUrl(int $postingId): ?string
    {
        $memberId = FundPosting::query()->whereKey($postingId)->value('member_id');

        if ($memberId !== null) {
            return self::safeResourceUrl(
                fn (): string => FundPostingResource::indexUrlForMember((int) $memberId),
            );
        }

        return self::safeResourceUrl(
            fn (): string => FundPostingResource::getUrl('index'),
        );
    }

    private static function cashOutRequestUrl(int $requestId): ?string
    {
        $memberId = CashOutRequest::query()->whereKey($requestId)->value('member_id');

        if ($memberId !== null) {
            return self::safeResourceUrl(
                fn (): string => CashOutRequestResource::indexUrlForMember((int) $memberId),
            );
        }

        return self::safeResourceUrl(
            fn (): string => CashOutRequestResource::getUrl('index'),
        );
    }

    private static function feeDeductionUrl(int $feeDeductionId): ?string
    {
        $memberId = FeeDeduction::query()->whereKey($feeDeductionId)->value('member_id');

        if ($memberId === null) {
            return null;
        }

        return self::safeResourceUrl(
            fn (): string => MemberResource::getUrl('view', ['record' => (int) $memberId]),
        );
    }

    private static function masterAccountTypeUrl(string $type): ?string
    {
        $accountId = Account::query()
            ->where('is_master', true)
            ->where('type', $type)
            ->value('id');

        if ($accountId !== null) {
            return self::safeResourceUrl(
                fn (): string => MasterAccountResource::getUrl('view', ['record' => (int) $accountId]),
            );
        }

        return self::safeResourceUrl(
            fn (): string => MasterAccountResource::listUrl($type),
        );
    }

    private static function ledgerTransactionUrl(int $transactionId): ?string
    {
        $transaction = Transaction::query()
            ->with('account')
            ->find($transactionId);

        $account = $transaction?->account;

        if ($account === null) {
            return null;
        }

        $transactionQuery = '?transaction='.$transactionId;

        if ($account->is_master && $account->type === 'bank') {
            return self::safeResourceUrl(
                fn (): string => BankAccountsResource::listUrl(BankClearingTabRegistry::TAB_LEDGER).$transactionQuery,
            );
        }

        if ($account->is_master) {
            return self::safeResourceUrl(
                fn (): string => MasterAccountResource::getUrl('view', ['record' => $account->id]).$transactionQuery,
            );
        }

        return self::safeResourceUrl(
            fn (): string => AccountResource::getUrl('view', ['record' => $account->id]).$transactionQuery,
        );
    }

    private static function linkHtml(string $label, string $url): HtmlString
    {
        return new HtmlString(
            '<a href="'.e($url).'" class="font-semibold text-sky-600 hover:underline dark:text-sky-400">'
            .e($label).'</a>'
        );
    }

    private static function safeResourceUrl(callable $resolver): ?string
    {
        try {
            return $resolver();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function currency(): string
    {
        return (string) Setting::get('general', 'currency', 'USD');
    }

    private static function metricValueIsMoney(string $field): bool
    {
        if (str_ends_with($field, '_count')) {
            return false;
        }

        return str_contains($field, 'amount')
            || str_contains($field, 'balance')
            || str_contains($field, 'delta')
            || str_contains($field, 'credits')
            || str_contains($field, 'debits')
            || str_ends_with($field, '_sum')
            || in_array($field, [
                'tolerance',
                'sum_member_cash',
                'sum_member_fund',
                'master_fund_pool',
                'null_reference_credits',
                'null_reference_debits',
                'null_reference_delta',
            ], true);
    }

    private static function formatMetricValue(string $field, mixed $value, string $currency): string
    {
        if (is_numeric($value) && self::metricValueIsMoney($field)) {
            return MoneyDisplay::format((float) $value, $currency) ?? (string) $value;
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_numeric($value) && str_ends_with($field, '_count')) {
            return number_format((int) $value);
        }

        return (string) $value;
    }
}
