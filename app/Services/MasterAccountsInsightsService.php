<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class MasterAccountsInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $currency = InsightFormatter::currency();
        $masters = Account::query()->where('is_master', true)->get()->keyBy('type');
        $balance = fn(string $type): float => (float) ($masters->get($type)?->balance ?? 0);

        $masterCash = $balance('cash');
        $masterFund = $balance('fund');
        $masterBank = $balance('bank');
        $masterExpense = $balance('expense');
        $masterInvest = $balance('invest');

        $loanExposure = (float) Loan::active()->get()->sum(
            fn(Loan $loan): float => $loan->getOutstandingBalance()
        );
        $activeLoanCount = Loan::active()->count();
        $coverage = $loanExposure > 0.01 ? round($masterFund / $loanExposure, 2) : null;
        $coveragePercent = $coverage !== null ? min(100, round($coverage * 100, 1)) : 100;

        $since = Carbon::now()->subDays(30);
        $activity = Transaction::query()
            ->whereHas('account', fn($query) => $query->where('is_master', true))
            ->where('transacted_at', '>=', $since)
            ->selectRaw("
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits,
                COUNT(*) as tx_count
            ")
            ->first();

        $activityCredits = (float) ($activity->credits ?? 0);
        $activityDebits = (float) ($activity->debits ?? 0);
        $activityNet = $activityCredits - $activityDebits;

        $memberCashStats = Account::query()
            ->where('is_master', false)
            ->where('type', 'cash')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(balance), 0) as total')
            ->first();

        $memberFundStats = Account::query()
            ->where('is_master', false)
            ->where('type', 'fund')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(balance), 0) as total')
            ->first();

        $negativeFundCount = Account::query()
            ->where('is_master', false)
            ->where('type', 'fund')
            ->where('balance', '<', 0)
            ->count();

        $zeroCashMembers = Member::query()
            ->active()
            ->whereHas('accounts', fn($query) => $query
                ->where('type', 'cash')
                ->where('is_master', false)
                ->where('balance', '<=', 0))
            ->count();

        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->startOfDay();
            $sparkline[] = Transaction::query()
                ->whereHas('account', fn($query) => $query->where('is_master', true))
                ->whereDate('transacted_at', $day)
                ->count();
        }

        $fundHealth = match (true) {
            $loanExposure <= 0 => 'healthy',
            $coverage !== null && $coverage >= 1 => 'healthy',
            $coverage !== null && $coverage >= 0.85 => 'monitor',
            default => 'action',
        };

        $indexUrl = MasterAccountResource::getUrl('index');
        $activityTxCount = (int) ($activity->tx_count ?? 0);

        return [
            'currency' => $currency,
            'master_fund' => $masterFund,
            'fund_health' => $fundHealth,
            'coverage' => $coverage,
            'coverage_percent' => $coveragePercent,
            'loan_exposure' => $loanExposure,
            'active_loan_count' => $activeLoanCount,
            'zero_cash_members' => $zeroCashMembers,
            'negative_fund_count' => $negativeFundCount,
            'activity_credits' => $activityCredits,
            'activity_debits' => $activityDebits,
            'activity_net' => $activityNet,
            'activity_tx_count' => (int) ($activity->tx_count ?? 0),
            'member_cash_total' => (float) ($memberCashStats->total ?? 0),
            'member_cash_count' => (int) ($memberCashStats->cnt ?? 0),
            'member_fund_total' => (float) ($memberFundStats->total ?? 0),
            'member_fund_count' => (int) ($memberFundStats->cnt ?? 0),
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'urls' => [
                'index' => $indexUrl,
                'loans' => LoanResource::getUrl('index'),
            ],
            'kpis' => $this->buildKpis(
                $masterCash,
                $masterFund,
                $masterBank,
                $masterExpense,
                $masterInvest,
                $activityNet,
                $activityTxCount,
            ),
            'hero' => $this->buildHero(
                $fundHealth,
                $coverage,
                $loanExposure,
                $activeLoanCount,
                $zeroCashMembers,
                $negativeFundCount,
                $indexUrl,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHero(
        string $fundHealth,
        ?float $coverage,
        float $loanExposure,
        int $activeLoanCount,
        int $zeroCashMembers,
        int $negativeFundCount,
        string $indexUrl,
    ): array {
        if ($fundHealth === 'action') {
            return [
                'tone' => 'danger',
                'title' => __('Fund coverage needs attention'),
                'subtitle' => __('Master fund covers :percent% of :amount outstanding across :count active loan(s).', [
                    'percent' => $coverage !== null ? round($coverage * 100, 0) : 0,
                    'amount' => InsightFormatter::money($loanExposure),
                    'count' => $activeLoanCount,
                ]),
                'cta_label' => __('View loans'),
                'cta_url' => LoanResource::getUrl('index'),
            ];
        }

        if ($zeroCashMembers > 0 || $negativeFundCount > 0) {
            return [
                'tone' => 'warning',
                'title' => __('Member balances need review'),
                'subtitle' => collect([
                    $zeroCashMembers > 0
                    ? trans_choice(':count member with no cash|:count members with no cash', $zeroCashMembers, ['count' => $zeroCashMembers])
                    : null,
                    $negativeFundCount > 0
                    ? trans_choice(':count negative fund account|:count negative fund accounts', $negativeFundCount, ['count' => $negativeFundCount])
                    : null,
                ])->filter()->implode(' · '),
                'cta_label' => __('Member accounts'),
                'cta_url' => AccountResource::getUrl('index'),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Master ledger is healthy'),
            'subtitle' => $loanExposure > 0
                ? __('Fund coverage at :ratio× across :count active loan(s).', [
                    'ratio' => number_format((float) $coverage, 2),
                    'count' => $activeLoanCount,
                ])
                : __('No active loan exposure. Pool balances are in sync.'),
            'cta_label' => __('Browse accounts'),
            'cta_url' => $indexUrl,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        float $masterCash,
        float $masterFund,
        float $masterBank,
        float $masterExpense,
        float $masterInvest,
        float $activityNet,
        int $activityTxCount,
    ): array {
        return [
            [
                'key' => 'cash',
                'label' => __('Cash'),
                'value' => InsightFormatter::compactAmount($masterCash),
                'sub' => InsightFormatter::money($masterCash),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'sky',
            ],
            [
                'key' => 'fund',
                'label' => __('Fund'),
                'value' => InsightFormatter::compactAmount($masterFund),
                'sub' => InsightFormatter::money($masterFund),
                'icon' => 'heroicon-o-building-library',
                'accent' => 'emerald',
            ],
            [
                'key' => 'bank',
                'label' => __('Bank'),
                'value' => InsightFormatter::compactAmount($masterBank),
                'sub' => InsightFormatter::money($masterBank),
                'icon' => 'heroicon-o-building-office-2',
                'accent' => 'indigo',
            ],
            [
                'key' => 'expense',
                'label' => __('Expense'),
                'value' => InsightFormatter::compactAmount($masterExpense),
                'sub' => InsightFormatter::money($masterExpense),
                'icon' => 'heroicon-o-receipt-percent',
                'accent' => 'rose',
            ],
            [
                'key' => 'invest',
                'label' => __('Invest'),
                'value' => InsightFormatter::compactAmount($masterInvest),
                'sub' => InsightFormatter::money($masterInvest),
                'icon' => 'heroicon-o-chart-bar',
                'accent' => 'violet',
            ],
            [
                'key' => 'activity',
                'label' => __('30d net'),
                'value' => ($activityNet >= 0 ? '+' : '−') . InsightFormatter::compactAmount($activityNet),
                'sub' => trans_choice(':count txn|:count txns', $activityTxCount, ['count' => $activityTxCount]),
                'icon' => 'heroicon-o-arrows-right-left',
                'accent' => $activityNet >= 0 ? 'teal' : 'amber',
                'value_class' => $activityNet >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-amber-600 dark:text-amber-400',
            ],
        ];
    }
}
