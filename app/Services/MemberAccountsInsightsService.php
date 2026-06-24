<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;

final class MemberAccountsInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $currency = InsightFormatter::currency();
        $activeTab = AccountResource::resolveListMemberAccountsTab();

        $memberCashStats = Account::query()
            ->where('is_master', false)
            ->where('type', 'cash')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(balance), 0) as total')
            ->first();

        $memberFundStats = Account::query()
            ->where('is_master', false)
            ->where('type', 'fund')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(balance), 0) as total, SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END) as negative_cnt')
            ->first();

        $memberCashTotal = (float) ($memberCashStats->total ?? 0);
        $memberCashCount = (int) ($memberCashStats->cnt ?? 0);
        $memberFundTotal = (float) ($memberFundStats->total ?? 0);
        $memberFundCount = (int) ($memberFundStats->cnt ?? 0);
        $negativeFundCount = (int) ($memberFundStats->negative_cnt ?? 0);

        $zeroCashMembers = Member::query()->activeWithZeroCash()->count();

        $activeMembers = Member::active()->count();
        $loanExposure = (float) Loan::active()->get()->sum(
            fn (Loan $loan): float => $loan->getOutstandingBalance()
        );
        $activeLoanCount = Loan::active()->count();
        $pendingContributions = Contribution::query()->where('status', 'pending')->count();

        $since = BusinessDay::now()->subDays(30);
        $activity = Transaction::query()
            ->whereHas('account', fn ($query) => $query->where('is_master', false))
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
        $activityTxCount = (int) ($activity->tx_count ?? 0);

        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        Transaction::query()
            ->whereHas('account', fn ($query) => $query->where('is_master', false))
            ->whereBetween('transacted_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['type', 'amount', 'transacted_at'])
            ->each(function (Transaction $transaction) use (&$monthTotals): void {
                $transactedAt = $transaction->transacted_at;

                if ($transactedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $transactedAt)->startOfMonth()->format('Y-m');
                $monthTotals[$key] ??= ['credits' => 0.0, 'debits' => 0.0];

                if ($transaction->type === 'credit') {
                    $monthTotals[$key]['credits'] += (float) $transaction->amount;

                    return;
                }

                if ($transaction->type === 'debit') {
                    $monthTotals[$key]['debits'] += (float) $transaction->amount;
                }
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $credits = (float) ($monthTotals[$key]['credits'] ?? 0.0);
            $debits = (float) ($monthTotals[$key]['debits'] ?? 0.0);

            $trend[] = [
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
                'credits' => $credits,
                'debits' => $debits,
                'total' => $credits + $debits,
            ];
        }

        $atRiskMembers = Member::query()
            ->active()
            ->with(['accounts' => fn ($query) => $query->whereIn('type', ['cash', 'fund'])->where('is_master', false)])
            ->where(function ($query): void {
                $query->whereHas('accounts', fn ($q) => $q
                    ->where('type', 'fund')
                    ->where('is_master', false)
                    ->where('balance', '<', 0))
                    ->orWhereHas('accounts', fn ($q) => $q
                        ->where('type', 'cash')
                        ->where('is_master', false)
                        ->where('balance', '<=', 0));
            })
            ->orderBy('name')
            ->limit(5)
            ->get()
            ->map(function (Member $member): array {
                $accounts = $member->accounts->keyBy('type');

                return [
                    'name' => $member->name,
                    'url' => MemberResource::getUrl('view', ['record' => $member]),
                    'cash' => (float) ($accounts->get('cash')?->balance ?? 0),
                    'fund' => (float) ($accounts->get('fund')?->balance ?? 0),
                ];
            })
            ->all();

        $indexUrl = AccountResource::getUrl('index');

        return [
            'currency' => $currency,
            'active_tab' => $activeTab,
            'active_tab_label' => match ($activeTab) {
                'fund' => __('Fund accounts'),
                'loans' => __('Loans'),
                'all' => __('All accounts'),
                default => __('Cash accounts'),
            },
            'zero_cash_members' => $zeroCashMembers,
            'negative_fund_count' => $negativeFundCount,
            'member_cash_total' => $memberCashTotal,
            'member_fund_total' => $memberFundTotal,
            'loan_exposure' => $loanExposure,
            'active_loan_count' => $activeLoanCount,
            'pending_contributions' => $pendingContributions,
            'active_members' => $activeMembers,
            'activity_credits' => $activityCredits,
            'activity_debits' => $activityDebits,
            'activity_net' => $activityNet,
            'activity_tx_count' => $activityTxCount,
            'trend' => DualProgressTrendBuilder::mapVolumeTrend($trend, 'credits', 'debits'),
            'at_risk_members' => $atRiskMembers,
            'urls' => [
                'index' => $indexUrl,
                'members' => MemberResource::getUrl('index'),
                'loans' => LoanResource::getUrl('index'),
                'contributions' => ContributionResource::getUrl('index'),
            ],
            'kpis' => [
                [
                    'key' => 'cash',
                    'label' => __('Member cash'),
                    'value' => InsightFormatter::compactAmount($memberCashTotal),
                    'sub' => trans_choice(':count acct|:count accts', $memberCashCount, ['count' => $memberCashCount]),
                    'icon' => 'heroicon-o-banknotes',
                    'accent' => 'sky',
                ],
                [
                    'key' => 'fund',
                    'label' => __('Member fund'),
                    'value' => InsightFormatter::compactAmount($memberFundTotal),
                    'sub' => trans_choice(':count acct|:count accts', $memberFundCount, ['count' => $memberFundCount]),
                    'icon' => 'heroicon-o-building-library',
                    'accent' => 'emerald',
                ],
                [
                    'key' => 'loans',
                    'label' => __('Outstanding'),
                    'value' => InsightFormatter::compactAmount($loanExposure),
                    'sub' => trans_choice(':count loan|:count loans', $activeLoanCount, ['count' => $activeLoanCount]),
                    'icon' => 'heroicon-o-document-text',
                    'accent' => 'amber',
                ],
                [
                    'key' => 'zero',
                    'label' => __('Zero cash'),
                    'value' => (string) $zeroCashMembers,
                    'sub' => __('Members'),
                    'icon' => 'heroicon-o-exclamation-triangle',
                    'accent' => $zeroCashMembers > 0 ? 'rose' : 'teal',
                ],
                [
                    'key' => 'negative',
                    'label' => __('Neg. fund'),
                    'value' => (string) $negativeFundCount,
                    'sub' => __('Accounts'),
                    'icon' => 'heroicon-o-arrow-trending-down',
                    'accent' => $negativeFundCount > 0 ? 'rose' : 'teal',
                ],
                [
                    'key' => 'net',
                    'label' => __('30d net'),
                    'value' => ($activityNet >= 0 ? '+' : '−').InsightFormatter::compactAmount($activityNet),
                    'sub' => trans_choice(':count txn|:count txns', $activityTxCount, ['count' => $activityTxCount]),
                    'icon' => 'heroicon-o-arrows-right-left',
                    'accent' => $activityNet >= 0 ? 'violet' : 'amber',
                    'value_class' => $activityNet >= 0
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-amber-600 dark:text-amber-400',
                ],
            ],
            'hero' => $this->buildHero(
                $zeroCashMembers,
                $negativeFundCount,
                $pendingContributions,
                $activeTab,
                $indexUrl,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHero(
        int $zeroCashMembers,
        int $negativeFundCount,
        int $pendingContributions,
        string $activeTab,
        string $indexUrl,
    ): array {
        if ($pendingContributions > 0) {
            return [
                'tone' => 'warning',
                'title' => __('Contributions awaiting posting'),
                'subtitle' => trans_choice(':count pending contribution needs review|:count pending contributions need review', $pendingContributions, [
                    'count' => $pendingContributions,
                ]),
                'cta_label' => __('Contributions'),
                'cta_url' => ContributionResource::getUrl('index'),
            ];
        }

        if ($zeroCashMembers > 0 || $negativeFundCount > 0) {
            return [
                'tone' => 'amber',
                'title' => __('Member balances need attention'),
                'subtitle' => collect([
                    $zeroCashMembers > 0
                    ? trans_choice(':count with no cash|:count with no cash', $zeroCashMembers, ['count' => $zeroCashMembers])
                    : null,
                    $negativeFundCount > 0
                    ? trans_choice(':count negative fund|:count negative funds', $negativeFundCount, ['count' => $negativeFundCount])
                    : null,
                ])->filter()->implode(' · '),
                'cta_label' => __('View members'),
                'cta_url' => MemberResource::getUrl('index'),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Member pool is healthy'),
            'subtitle' => __('Viewing :tab — cash and fund totals are in line.', [
                'tab' => match ($activeTab) {
                    'fund' => __('fund accounts'),
                    'loans' => __('loans'),
                    'all' => __('all accounts'),
                    default => __('cash accounts'),
                },
            ]),
            'cta_label' => __('Browse accounts'),
            'cta_url' => $indexUrl,
        ];
    }
}
