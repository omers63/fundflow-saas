<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberDateDisplay;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MemberPortalAccountsInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $member->loadMissing(['cashAccount', 'fundAccount']);
        $currency = InsightFormatter::currency();

        $cashBalance = $member->getCashBalance();
        $fundBalance = $member->getFundBalance();
        $netWorth = $cashBalance + $fundBalance;

        $accountIds = Account::query()
            ->where('member_id', $member->id)
            ->where('is_master', false)
            ->pluck('id');

        $since = BusinessDay::now()->subDays(30);
        $activity = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('transacted_at', '>=', $since)
            ->selectRaw("
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits,
                COUNT(*) as tx_count
            ")
            ->first();

        $credits30 = (float) ($activity->credits ?? 0);
        $debits30 = (float) ($activity->debits ?? 0);
        $net30 = $credits30 - $debits30;
        $txCount30 = (int) ($activity->tx_count ?? 0);

        $activeLoan = $member->loans()->active()->with('installments')->first();
        $loanOutstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;
        $activeLoanCount = $member->loans()->active()->count();

        $pendingDeposits = (int) FundPosting::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->count();

        [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();
        $postedThisCycle = Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->exists();

        $trend = $this->sixMonthTrend($accountIds);

        $sparklineCounts = [];
        $sparklineWindowStart = BusinessDay::now()->subDays(6)->startOfDay();
        $sparklineWindowEnd = BusinessDay::now()->endOfDay();

        Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('transacted_at', [$sparklineWindowStart, $sparklineWindowEnd])
            ->get(['transacted_at'])
            ->each(function (Transaction $transaction) use (&$sparklineCounts): void {
                $transactedAt = $transaction->transacted_at;

                if ($transactedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $transactedAt)->startOfDay()->toDateString();
                $sparklineCounts[$key] = ($sparklineCounts[$key] ?? 0) + 1;
            });

        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = BusinessDay::now()->subDays($i)->startOfDay()->toDateString();
            $sparkline[] = $sparklineCounts[$day] ?? 0;
        }

        $recent = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->with('account')
            ->orderByDesc('transacted_at')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'description' => Str::limit($transaction->memberFacingDescription(), 40),
                'transacted_at' => MemberDateDisplay::format($transaction->transacted_at, 'd M, H:i') ?? '—',
                'amount' => InsightFormatter::money((float) $transaction->amount),
                'signed_class' => $transaction->type === 'credit'
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
                'prefix' => $transaction->type === 'credit' ? '+' : '−',
                'account_type' => $transaction->account?->type,
            ])
            ->all();

        $indexUrl = MyAccountResource::getUrl('index');

        return [
            'currency' => $currency,
            'cash_balance' => $cashBalance,
            'fund_balance' => $fundBalance,
            'net_worth' => $netWorth,
            'fund_negative' => $fundBalance < 0,
            'cash_low' => $cashBalance <= 0,
            'credits30' => $credits30,
            'debits30' => $debits30,
            'net30' => $net30,
            'tx_count30' => $txCount30,
            'loan_outstanding' => $loanOutstanding,
            'active_loan_count' => $activeLoanCount,
            'pending_deposits' => $pendingDeposits,
            'posted_this_cycle' => $postedThisCycle,
            'trend' => $trend,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'recent' => $recent,
            'accounts' => [
                'cash' => [
                    'label' => __('Cash'),
                    'balance' => InsightFormatter::money($cashBalance),
                    'url' => $member->cashAccount
                        ? MyAccountResource::getUrl('view', ['record' => $member->cashAccount])
                        : MyAccountResource::listUrl('cash'),
                ],
                'fund' => [
                    'label' => __('Fund'),
                    'balance' => InsightFormatter::money($fundBalance),
                    'negative' => $fundBalance < 0,
                    'url' => $member->fundAccount
                        ? MyAccountResource::getUrl('view', ['record' => $member->fundAccount])
                        : MyAccountResource::listUrl('fund'),
                ],
            ],
            'urls' => [
                'index' => $indexUrl,
                'cash' => MyAccountResource::listUrl('cash'),
                'fund' => MyAccountResource::listUrl('fund'),
                'loans' => MyAccountResource::listUrl('loans'),
                'deposits' => MyFundPostingResource::getUrl('index'),
                'deposits_create' => MyFundPostingResource::getUrl('create'),
                'contributions' => MyContributionResource::getUrl('index'),
                'loans_resource' => MyLoanResource::getUrl('index'),
                'active_loan' => $activeLoan
                    ? MyLoanResource::getUrl('view', ['record' => $activeLoan])
                    : null,
            ],
            'hero' => $this->buildHero(
                $cashBalance,
                $fundBalance,
                $pendingDeposits,
                $loanOutstanding,
                $activeLoan,
                $postedThisCycle,
            ),
            'kpis' => $this->buildKpis(
                $cashBalance,
                $fundBalance,
                $netWorth,
                $loanOutstanding,
                $activeLoanCount,
                $pendingDeposits,
                $net30,
                $txCount30,
            ),
        ];
    }

    /**
     * @return array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}
     */
    private function buildHero(
        float $cashBalance,
        float $fundBalance,
        int $pendingDeposits,
        float $loanOutstanding,
        ?Loan $activeLoan,
        bool $postedThisCycle,
    ): array {
        if ($fundBalance < 0) {
            return [
                'tone' => 'danger',
                'title' => __('Fund balance is below zero'),
                'subtitle' => __('This often reflects an active loan allocation against your fund account.'),
                'cta_label' => $activeLoan ? __('View loan') : __('View fund'),
                'cta_url' => $activeLoan
                    ? MyLoanResource::getUrl('view', ['record' => $activeLoan])
                    : MyAccountResource::listUrl('fund'),
            ];
        }

        if ($cashBalance <= 0 && $pendingDeposits > 0) {
            return [
                'tone' => 'amber',
                'title' => __('Deposits awaiting approval'),
                'subtitle' => trans_choice(':count deposit is pending|:count deposits are pending', $pendingDeposits, ['count' => $pendingDeposits]),
                'cta_label' => __('View deposits'),
                'cta_url' => MyFundPostingResource::getUrl('index'),
            ];
        }

        if ($cashBalance <= 0) {
            return [
                'tone' => 'amber',
                'title' => __('Top up your cash account'),
                'subtitle' => __('Submit a deposit to cover contributions or loan repayments.'),
                'cta_label' => __('New deposit'),
                'cta_url' => MyFundPostingResource::getUrl('create'),
            ];
        }

        if ($activeLoan !== null && $loanOutstanding > 0) {
            return [
                'tone' => 'sky',
                'title' => __('Active loan in progress'),
                'subtitle' => __('Outstanding :amount across your accounts.', ['amount' => InsightFormatter::money($loanOutstanding)]),
                'cta_label' => __('View loan'),
                'cta_url' => MyLoanResource::getUrl('view', ['record' => $activeLoan]),
            ];
        }

        if (! $postedThisCycle) {
            return [
                'tone' => 'amber',
                'title' => __('Open contribution cycle'),
                'subtitle' => __('Your contribution for this period is not posted yet.'),
                'cta_label' => __('Contributions'),
                'cta_url' => MyContributionResource::getUrl('index'),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Your accounts look healthy'),
            'subtitle' => __('Cash, fund, loans, and recent ledger movement in one place.'),
            'cta_label' => null,
            'cta_url' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        float $cashBalance,
        float $fundBalance,
        float $netWorth,
        float $loanOutstanding,
        int $activeLoanCount,
        int $pendingDeposits,
        float $net30,
        int $txCount30,
    ): array {
        $kpis = [
            [
                'label' => __('Cash'),
                'value' => InsightFormatter::compactAmount($cashBalance),
                'sub' => InsightFormatter::money($cashBalance),
                'icon' => 'heroicon-o-wallet',
                'accent' => $cashBalance > 0 ? 'sky' : 'amber',
                'url' => MyAccountResource::listUrl('cash'),
                'value_class' => $cashBalance > 0
                    ? 'text-sky-600 dark:text-sky-400'
                    : 'text-amber-600 dark:text-amber-400',
            ],
            [
                'label' => __('Fund'),
                'value' => InsightFormatter::compactAmount($fundBalance),
                'sub' => InsightFormatter::money($fundBalance),
                'icon' => 'heroicon-o-building-library',
                'accent' => $fundBalance < 0 ? 'rose' : 'indigo',
                'url' => MyAccountResource::listUrl('fund'),
                'value_class' => $fundBalance < 0
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-indigo-600 dark:text-indigo-400',
            ],
            [
                'label' => __('Net worth'),
                'value' => InsightFormatter::compactAmount($netWorth),
                'sub' => __('Cash + fund'),
                'icon' => 'heroicon-o-scale',
                'accent' => 'emerald',
                'url' => MyAccountResource::listUrl('all'),
            ],
            [
                'label' => __('Loan due'),
                'value' => $loanOutstanding > 0 ? InsightFormatter::compactAmount($loanOutstanding) : '—',
                'sub' => trans_choice(':count active|:count active', $activeLoanCount, ['count' => $activeLoanCount]),
                'icon' => 'heroicon-o-currency-dollar',
                'accent' => $loanOutstanding > 0 ? 'violet' : 'gray',
                'url' => MyAccountResource::listUrl('loans'),
            ],
            [
                'label' => __('Deposits'),
                'value' => (string) $pendingDeposits,
                'sub' => __('Pending'),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'accent' => $pendingDeposits > 0 ? 'amber' : 'teal',
                'url' => MyFundPostingResource::getUrl('index'),
            ],
            [
                'label' => __('30d net'),
                'value' => ($net30 >= 0 ? '+' : '−').InsightFormatter::compactAmount(abs($net30)),
                'sub' => trans_choice(':count txn|:count txns', $txCount30, ['count' => $txCount30]),
                'icon' => 'heroicon-o-arrows-right-left',
                'accent' => $net30 >= 0 ? 'teal' : 'amber',
                'value_class' => $net30 >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-amber-600 dark:text-amber-400',
            ],
        ];

        return $kpis;
    }

    /**
     * @param  Collection<int, int>  $accountIds
     * @return list<array{label: string, credits: float, debits: float, total: float}>
     */
    private function sixMonthTrend(Collection $accountIds): array
    {
        if ($accountIds->isEmpty()) {
            return [];
        }

        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        Transaction::query()
            ->whereIn('account_id', $accountIds)
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

        return DualProgressTrendBuilder::mapVolumeTrend($trend, 'credits', 'debits');
    }
}
