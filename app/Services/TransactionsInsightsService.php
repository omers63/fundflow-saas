<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Support\AccountTransactionTypeFilter;
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
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\Insights\InsightKpi;
use App\Support\TransactionBusinessTypeCatalog;
use Illuminate\Database\Eloquent\Builder;

final class TransactionsInsightsService
{
    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $tableFilters
     * @return array<string, mixed>
     */
    public function snapshot(Builder $query, array $tableFilters = [], ?string $tableSearch = null): array
    {
        $summary = $this->summarize($query);
        $transactionCount = (int) ($summary->transaction_count ?? 0);
        $creditsTotal = (float) ($summary->credits_total ?? 0);
        $debitsTotal = (float) ($summary->debits_total ?? 0);
        $netTotal = $creditsTotal - $debitsTotal;
        $linkedSourceCount = (int) ($summary->linked_source_count ?? 0);
        $manualOrUnlinkedCount = (int) ($summary->manual_or_unlinked_count ?? 0);
        $uniqueMembers = (int) ($summary->unique_members ?? 0);
        $uniqueAccounts = (int) ($summary->unique_accounts ?? 0);

        $scopeBreakdown = $this->scopeBreakdown($query, $transactionCount);
        $accountTypeBreakdown = $this->accountTypeBreakdown($query, $transactionCount);
        $businessTypeBreakdown = $this->businessTypeBreakdown($query, $transactionCount);
        $trend = $this->trend($query);
        $sparkline = array_map(
            static fn (array $day): float => (float) $day['flow_total'],
            $trend,
        );

        $topBusinessType = collect($businessTypeBreakdown)->first();
        $linkedSourcePercent = $transactionCount > 0
            ? round(($linkedSourceCount / $transactionCount) * 100, 1)
            : null;

        return [
            'hero' => $this->buildHero($transactionCount, $tableFilters, $tableSearch),
            'kpis' => $this->buildKpis(
                transactionCount: $transactionCount,
                creditsTotal: $creditsTotal,
                debitsTotal: $debitsTotal,
                netTotal: $netTotal,
                uniqueMembers: $uniqueMembers,
                uniqueAccounts: $uniqueAccounts,
                linkedSourceCount: $linkedSourceCount,
                linkedSourcePercent: $linkedSourcePercent,
                manualOrUnlinkedCount: $manualOrUnlinkedCount,
                topBusinessType: $topBusinessType,
            ),
            'sparkline' => $sparkline,
            'sparkline_max' => empty($sparkline) ? 1 : max(1, (float) max($sparkline)),
            'summary' => [
                'transaction_count' => $transactionCount,
                'credits_total' => $creditsTotal,
                'debits_total' => $debitsTotal,
                'net_total' => $netTotal,
                'linked_source_count' => $linkedSourceCount,
                'manual_or_unlinked_count' => $manualOrUnlinkedCount,
                'unique_members' => $uniqueMembers,
                'unique_accounts' => $uniqueAccounts,
                'linked_source_percent' => $linkedSourcePercent,
            ],
            'filters' => $this->buildFilterSummary($tableFilters, $tableSearch),
            'trend' => $trend,
            'breakdowns' => [
                'scope' => $scopeBreakdown,
                'account_type' => $accountTypeBreakdown,
                'business_type' => array_slice($businessTypeBreakdown, 0, 6),
            ],
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function summarize(Builder $query): object
    {
        return $this->baseQuery($query)
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credits_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debits_total")
            ->selectRaw('COUNT(DISTINCT member_id) as unique_members')
            ->selectRaw('COUNT(DISTINCT account_id) as unique_accounts')
            ->selectRaw('SUM(CASE WHEN reference_type IS NOT NULL AND reference_id IS NOT NULL THEN 1 ELSE 0 END) as linked_source_count')
            ->selectRaw('SUM(CASE WHEN reference_type IS NULL OR reference_id IS NULL THEN 1 ELSE 0 END) as manual_or_unlinked_count')
            ->first();
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array<string, mixed>>
     */
    private function scopeBreakdown(Builder $query, int $transactionCount): array
    {
        $rows = [];

        foreach ([
            'master' => true,
            'member' => false,
        ] as $label => $isMaster) {
            $summary = $this->summarize(
                $this->baseQuery($query)->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('is_master', $isMaster))
            );

            $rowTransactionCount = (int) ($summary->transaction_count ?? 0);

            if ($rowTransactionCount === 0) {
                continue;
            }

            $rows[] = (object) [
                'group_key' => $label,
                'transaction_count' => $rowTransactionCount,
                'credits_total' => (float) ($summary->credits_total ?? 0),
                'debits_total' => (float) ($summary->debits_total ?? 0),
            ];
        }

        usort($rows, fn (object $left, object $right): int => $right->transaction_count <=> $left->transaction_count);

        return $this->mapBreakdownRows(
            $rows,
            $transactionCount,
            static fn (object $row): string => $row->group_key === 'master' ? __('Master') : __('Member'),
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array<string, mixed>>
     */
    private function accountTypeBreakdown(Builder $query, int $transactionCount): array
    {
        $rows = [];

        foreach (['cash', 'fund', 'bank', 'expense', 'fees', 'invest', 'loan', 'suspense'] as $accountType) {
            $summary = $this->summarize(
                $this->baseQuery($query)->whereHas('account', fn (Builder $accountQuery): Builder => $accountQuery->where('type', $accountType))
            );

            $rowTransactionCount = (int) ($summary->transaction_count ?? 0);

            if ($rowTransactionCount === 0) {
                continue;
            }

            $rows[] = (object) [
                'group_key' => $accountType,
                'transaction_count' => $rowTransactionCount,
                'credits_total' => (float) ($summary->credits_total ?? 0),
                'debits_total' => (float) ($summary->debits_total ?? 0),
            ];
        }

        usort($rows, fn (object $left, object $right): int => $right->transaction_count <=> $left->transaction_count);

        return $this->mapBreakdownRows(
            $rows,
            $transactionCount,
            fn (object $row): string => $this->accountTypeLabel($row->group_key),
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array<string, mixed>>
     */
    private function businessTypeBreakdown(Builder $query, int $transactionCount): array
    {
        $transactionTable = (new Transaction)->getTable();
        $caseSql = $this->businessTypeCaseSql();
        $bindings = $this->businessTypeCaseBindings();

        return $this->mapBreakdownRows(
            $this->baseQuery($query)
                ->selectRaw("{$caseSql} as group_key", $bindings)
                ->selectRaw('COUNT(*) as transaction_count')
                ->selectRaw("COALESCE(SUM(CASE WHEN {$transactionTable}.type = 'credit' THEN {$transactionTable}.amount ELSE 0 END), 0) as credits_total")
                ->selectRaw("COALESCE(SUM(CASE WHEN {$transactionTable}.type = 'debit' THEN {$transactionTable}.amount ELSE 0 END), 0) as debits_total")
                ->groupBy('group_key')
                ->orderByDesc('transaction_count')
                ->get(),
            $transactionCount,
            static fn (object $row): string => TransactionBusinessTypeCatalog::labelForKey((string) $row->group_key),
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return list<array<string, mixed>>
     */
    private function trend(Builder $query): array
    {
        $now = BusinessDay::now();
        $windowStart = $now->copy()->subDays(29)->startOfDay();
        $dailyTotals = [];

        $this->baseQuery($query)
            ->where('transacted_at', '>=', $windowStart)
            ->selectRaw('DATE(transacted_at) as trend_day')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credits_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debits_total")
            ->groupBy('trend_day')
            ->orderBy('trend_day')
            ->get()
            ->each(function (object $row) use (&$dailyTotals): void {
                $dailyTotals[(string) $row->trend_day] = [
                    'credits_total' => (float) $row->credits_total,
                    'debits_total' => (float) $row->debits_total,
                ];
            });

        $trend = [];
        $maxFlow = 0.0;
        $maxNet = 0.0;

        for ($i = 29; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->startOfDay();
            $key = $day->toDateString();
            $creditsTotal = (float) ($dailyTotals[$key]['credits_total'] ?? 0);
            $debitsTotal = (float) ($dailyTotals[$key]['debits_total'] ?? 0);
            $flowTotal = $creditsTotal + $debitsTotal;
            $netTotal = $creditsTotal - $debitsTotal;

            $maxFlow = max($maxFlow, $flowTotal);
            $maxNet = max($maxNet, abs($netTotal));

            $trend[] = [
                'label' => $day->locale(app()->getLocale())->translatedFormat('M j'),
                'credits_total' => $creditsTotal,
                'debits_total' => $debitsTotal,
                'net_total' => $netTotal,
                'flow_total' => $flowTotal,
            ];
        }

        foreach ($trend as &$day) {
            $day['flow_bar'] = $maxFlow > 0 ? max(4, round(($day['flow_total'] / $maxFlow) * 100)) : 0;
            $day['net_bar'] = $maxNet > 0 ? max(4, round((abs($day['net_total']) / $maxNet) * 100)) : 0;
            $day['tone'] = $day['net_total'] > 0
                ? 'emerald'
                : ($day['net_total'] < 0 ? 'rose' : 'gray');
        }
        unset($day);

        return $trend;
    }

    /**
     * @param  Builder<Transaction>  $query
     */
    private function baseQuery(Builder $query): Builder
    {
        return (clone $query)
            ->reorder()
            ->withoutEagerLoads();
    }

    /**
     * @param  iterable<object>  $rows
     * @param  callable(object): string  $labelResolver
     * @return list<array<string, mixed>>
     */
    private function mapBreakdownRows(iterable $rows, int $transactionCount, callable $labelResolver): array
    {
        $mapped = [];

        foreach ($rows as $row) {
            $rowTransactionCount = (int) ($row->transaction_count ?? 0);
            $creditsTotal = (float) ($row->credits_total ?? 0);
            $debitsTotal = (float) ($row->debits_total ?? 0);
            $netTotal = $creditsTotal - $debitsTotal;
            $sharePercent = $transactionCount > 0
                ? round(($rowTransactionCount / $transactionCount) * 100, 1)
                : 0.0;

            $mapped[] = [
                'label' => $labelResolver($row),
                'count' => $rowTransactionCount,
                'share_percent' => $sharePercent,
                'count_display' => trans_choice(':count txn|:count txns', $rowTransactionCount, ['count' => $rowTransactionCount]),
                'credits_display' => InsightFormatter::money($creditsTotal),
                'debits_display' => InsightFormatter::money($debitsTotal),
                'net_display' => InsightFormatter::money($netTotal),
                'bar_width' => max($rowTransactionCount > 0 ? 6 : 0, (int) round($sharePercent)),
                'tone' => $netTotal > 0 ? 'emerald' : ($netTotal < 0 ? 'rose' : 'gray'),
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $tableFilters
     * @return array<string, string>
     */
    private function buildFilterSummary(array $tableFilters, ?string $tableSearch): array
    {
        $linkedSourceFilter = $this->selectedFilterValue($tableFilters, 'has_linked_source');
        $transactionType = $this->selectedFilterValue($tableFilters, 'business_type');

        return [
            'scope' => match ($this->selectedFilterValue($tableFilters, 'account_class')) {
                'master' => __('Master'),
                'member' => __('Member'),
                default => __('All scopes'),
            },
            'direction' => AccountTransactionTypeFilter::options()[(string) $this->selectedFilterValue($tableFilters, 'type')] ?? __('All directions'),
            'account_type' => $this->accountTypeLabel($this->selectedFilterValue($tableFilters, 'account_type')) ?: __('All account types'),
            'transaction_type' => filled($transactionType)
                ? TransactionBusinessTypeCatalog::labelForKey((string) $transactionType)
                : __('All transaction types'),
            'linked_source' => match ($linkedSourceFilter) {
                true, 'true', 1, '1' => __('Has linked source'),
                false, 'false', 0, '0' => __('Missing linked source'),
                default => __('All linked-source states'),
            },
            'search' => filled($tableSearch) ? (string) $tableSearch : __('—'),
        ];
    }

    /**
     * @param  array<string, mixed>  $tableFilters
     * @return array<string, mixed>
     */
    private function buildHero(int $transactionCount, array $tableFilters, ?string $tableSearch): array
    {
        $filters = $this->buildFilterSummary($tableFilters, $tableSearch);

        return [
            'tone' => $transactionCount > 0 ? 'sky' : 'warning',
            'title' => $transactionCount > 0
                ? __('Filtered ledger activity across member and master books.')
                : __('No transactions match the current view.'),
            'subtitle' => __(':count transactions · Scope: :scope · Search: :search', [
                'count' => number_format($transactionCount),
                'scope' => $filters['scope'],
                'search' => $filters['search'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $topBusinessType
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        int $transactionCount,
        float $creditsTotal,
        float $debitsTotal,
        float $netTotal,
        int $uniqueMembers,
        int $uniqueAccounts,
        int $linkedSourceCount,
        ?float $linkedSourcePercent,
        int $manualOrUnlinkedCount,
        ?array $topBusinessType,
    ): array {
        $currency = InsightFormatter::currency();

        return [
            [
                'key' => 'net',
                'label' => __('Net flow'),
                ...InsightKpi::moneyValue($netTotal, $currency),
                'sub' => trans_choice(':count txn|:count txns', $transactionCount, ['count' => $transactionCount]),
                'accent' => $netTotal >= 0 ? 'emerald' : 'rose',
                'value_class' => $netTotal >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
            ],
            [
                'key' => 'credits',
                'label' => __('Credits'),
                ...InsightKpi::moneyValue($creditsTotal, $currency),
                'sub' => __('Incoming flow'),
                'accent' => 'emerald',
            ],
            [
                'key' => 'debits',
                'label' => __('Debits'),
                ...InsightKpi::moneyValue($debitsTotal, $currency),
                'sub' => __('Outgoing flow'),
                'accent' => 'rose',
            ],
            [
                'key' => 'members',
                'label' => __('Members'),
                ...InsightKpi::countValue($uniqueMembers),
                'sub' => __('Affected'),
                'accent' => 'sky',
            ],
            [
                'key' => 'accounts',
                'label' => __('Accounts'),
                ...InsightKpi::countValue($uniqueAccounts),
                'sub' => __('Affected'),
                'accent' => 'violet',
            ],
            [
                'key' => 'coverage',
                'label' => __('Linked source'),
                ...InsightKpi::countValue($linkedSourcePercent === null ? '—' : number_format($linkedSourcePercent, 1).'%'),
                'sub' => __(':linked linked · :manual manual', [
                    'linked' => number_format($linkedSourceCount),
                    'manual' => number_format($manualOrUnlinkedCount),
                ]),
                'accent' => $manualOrUnlinkedCount > 0 ? 'amber' : 'teal',
            ],
            [
                'key' => 'top_type',
                'label' => __('Top type'),
                ...InsightKpi::countValue($topBusinessType['label'] ?? __('—')),
                'sub' => $topBusinessType === null
                    ? __('No activity')
                    : trans_choice(':count txn|:count txns', (int) ($topBusinessType['count'] ?? 0), [
                        'count' => (int) ($topBusinessType['count'] ?? 0),
                    ]),
                'accent' => 'gray',
            ],
        ];
    }

    private function accountTypeLabel(mixed $accountType): string
    {
        return match ($accountType) {
            'cash' => __('Cash'),
            'fund' => __('Fund'),
            'bank' => __('Bank'),
            'expense' => __('Expense'),
            'fees' => __('Fees'),
            'invest' => __('Invest'),
            'loan' => __('Loan'),
            'suspense' => __('Suspense'),
            null, '' => '',
            default => __('Other'),
        };
    }

    private function selectedFilterValue(array $tableFilters, string $key): mixed
    {
        $filter = $tableFilters[$key] ?? null;

        if (! is_array($filter)) {
            return $filter;
        }

        if (array_key_exists('value', $filter) && filled($filter['value'])) {
            return $filter['value'];
        }

        if (array_key_exists('isActive', $filter)) {
            return $filter['isActive'];
        }

        if (array_key_exists('state', $filter)) {
            return $filter['state'];
        }

        return null;
    }

    private function businessTypeCaseSql(): string
    {
        return <<<'SQL'
CASE
    WHEN description LIKE ? OR description LIKE ? THEN 'late_fee'
    WHEN description LIKE ? OR description LIKE ? OR description LIKE ? THEN 'transfer'
    WHEN reference_id IS NULL OR reference_type IS NULL THEN 'manual'
    WHEN reference_type = ? THEN 'reversal'
    WHEN reference_type = ? THEN 'contribution'
    WHEN reference_type IN (?, ?) THEN 'emi'
    WHEN reference_type = ? THEN 'deposit'
    WHEN reference_type = ? THEN 'cash_out'
    WHEN reference_type = ? THEN 'loan'
    WHEN reference_type = ? THEN 'late_fee'
    WHEN reference_type = ? THEN 'bank_import'
    WHEN reference_type = ? THEN 'membership_application'
    WHEN reference_type = ? THEN 'investment'
    WHEN reference_type = ? THEN 'investment_return'
    WHEN reference_type = ? THEN 'expense'
    ELSE 'other'
END
SQL;
    }

    /**
     * @return list<string>
     */
    private function businessTypeCaseBindings(): array
    {
        return [
            'Contribution late fee —%',
            'EMI late fee —%',
            'Transfer to%',
            'Transfer from%',
            'Allocation —%',
            Transaction::class,
            Contribution::class,
            LoanInstallment::class,
            LoanRepayment::class,
            FundPosting::class,
            CashOutRequest::class,
            Loan::class,
            FeeDeduction::class,
            BankTransaction::class,
            MembershipApplication::class,
            InvestDisbursement::class,
            InvestReturn::class,
            ExpenseDisbursement::class,
        ];
    }
}
