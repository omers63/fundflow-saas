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
use App\Support\Insights\InsightFormatter;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class MemberPortalAccountDetailInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Account $account): array
    {
        $member = CurrentMember::get();

        if (
            $member === null
            || (int) $account->member_id !== (int) $member->id
            || $account->is_master
        ) {
            return [];
        }

        $account->loadMissing('member');
        $account = $account->fresh() ?? $account;
        $memberMetrics = $this->memberContextMetrics($member);

        $currency = InsightFormatter::currency();
        $balance = (float) $account->balance;
        $now = BusinessDay::now();
        $since = $now->copy()->subDays(30);

        $stats30 = Transaction::query()
            ->where('account_id', $account->id)
            ->where('transacted_at', '>=', $since)
            ->selectRaw("
                SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as debits,
                COUNT(*) as tx_count
            ")
            ->first();

        $credits30 = (float) ($stats30->credits ?? 0);
        $debits30 = (float) ($stats30->debits ?? 0);
        $net30 = $credits30 - $debits30;
        $txCount30 = (int) ($stats30->tx_count ?? 0);
        $totalTx = Transaction::query()->where('account_id', $account->id)->count();

        $sparklineCounts = [];
        $sparklineWindowStart = $now->copy()->subDays(6)->startOfDay();
        $sparklineWindowEnd = $now->copy()->endOfDay();

        Transaction::query()
            ->where('account_id', $account->id)
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
            $day = $now->copy()->subDays($i)->startOfDay()->toDateString();
            $sparkline[] = $sparklineCounts[$day] ?? 0;
        }

        $recent = Transaction::query()
            ->where('account_id', $account->id)
            ->orderByDesc('transacted_at')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'description' => Str::limit($transaction->memberFacingDescription(), 48),
                'transacted_at' => $transaction->transacted_at?->format('M j, H:i'),
                'type' => $transaction->type,
                'amount_value' => (float) $transaction->amount,
                'signed_class' => $transaction->type === 'credit'
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
                'is_reversal' => $transaction->reference_type === Transaction::class,
            ])
            ->all();

        $typeLabel = match ($account->type) {
            'cash' => __('Cash'),
            'fund' => __('Fund'),
            default => ucfirst($account->type),
        };

        $context = $this->buildContext($account, $member, $balance, $memberMetrics);
        $kpis = $this->buildKpis($balance, $credits30, $debits30, $net30, $txCount30, $totalTx, $context);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'type_label' => $typeLabel,
            ],
            'currency' => $currency,
            'balance' => $balance,
            'balance_display' => InsightFormatter::money($balance),
            'balance_negative' => $balance < 0,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'recent' => $recent,
            'hero' => $this->buildHero($account, $balance, $member, $memberMetrics),
            'kpis' => $kpis,
            'context' => $context,
        ];
    }

    /**
     * @return array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}
     */
    private function buildHero(Account $account, float $balance, Member $member, array $memberMetrics): array
    {
        if ($account->type === 'fund' && $balance < 0) {
            $activeLoan = $memberMetrics['active_loan'];

            return [
                'tone' => 'danger',
                'title' => __('Fund is below zero'),
                'subtitle' => __('Negative fund balance usually reflects loan allocation against your savings.'),
                'cta_label' => $activeLoan ? __('View loan') : null,
                'cta_url' => $activeLoan
                    ? MyLoanResource::getUrl('view', ['record' => $activeLoan])
                    : null,
            ];
        }

        if ($account->type === 'cash' && $balance <= 0) {
            $pending = $memberMetrics['pending_postings'];

            return [
                'tone' => 'amber',
                'title' => __('No available cash'),
                'subtitle' => $pending > 0
                    ? trans_choice(':count deposit pending approval|:count deposits pending approval', $pending, ['count' => $pending])
                    : __('Submit a deposit to fund contributions or repayments.'),
                'cta_label' => $pending > 0 ? __('Deposits') : __('New deposit'),
                'cta_url' => $pending > 0
                    ? MyFundPostingResource::getUrl('index')
                    : MyFundPostingResource::getUrl('create'),
            ];
        }

        return [
            'tone' => $balance >= 0 ? 'success' : 'warning',
            'title' => $account->name,
            'subtitle' => match ($account->type) {
                'cash' => __('Available for contributions and repayments'),
                'fund' => __('Long-term fund savings'),
                default => __('Member account ledger'),
            },
            'cta_label' => null,
            'cta_url' => null,
        ];
    }

    /**
     * @return array{panels: list<array<string, mixed>>, sixth_kpi: ?array<string, mixed>}
     */
    private function buildContext(Account $account, Member $member, float $balance, array $memberMetrics): array
    {
        $member->loadMissing(['cashAccount', 'fundAccount']);
        $activeLoan = $memberMetrics['active_loan'];

        $outstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;
        $pendingPostings = $memberMetrics['pending_postings'];

        $otherAccount = $account->type === 'cash' ? $member->fundAccount : $member->cashAccount;
        $otherBalance = $account->type === 'cash'
            ? $member->getFundBalance()
            : $member->getCashBalance();

        $panels = [
            [
                'title' => __('Linked account'),
                'rows' => [
                    [
                        'label' => $account->type === 'cash' ? __('Fund balance') : __('Cash balance'),
                        'value' => InsightFormatter::money($otherBalance),
                    ],
                    [
                        'label' => __('Loan outstanding'),
                        'value' => InsightFormatter::money($outstanding),
                    ],
                ],
                'url' => $otherAccount
                    ? MyAccountResource::getUrl('view', ['record' => $otherAccount])
                    : null,
                'link_label' => $account->type === 'cash' ? __('View fund') : __('View cash'),
            ],
        ];

        $sixthKpi = null;

        if ($account->type === 'cash') {
            $panels[] = [
                'title' => __('Cash readiness'),
                'rows' => [
                    [
                        'label' => __('Pending deposits'),
                        'value' => (string) $pendingPostings,
                    ],
                    [
                        'label' => __('Monthly contribution'),
                        'value' => InsightFormatter::money((float) $member->monthly_contribution_amount),
                    ],
                ],
                'url' => MyFundPostingResource::getUrl('index'),
                'link_label' => __('Deposits'),
            ];

            $sixthKpi = [
                'label' => __('Deposits'),
                'value' => (string) $pendingPostings,
                'sub' => __('Pending'),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'accent' => $pendingPostings > 0 ? 'amber' : 'teal',
            ];
        }

        if ($account->type === 'fund') {
            $monthly = (float) $member->monthly_contribution_amount;
            $pct = $monthly > 0 ? min(100, (int) round(($balance / $monthly) * 100)) : null;

            [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();
            $postedCycle = Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod($openMonth, $openYear)
                ->posted()
                ->exists();

            $panels[] = [
                'title' => __('Fund progress'),
                'rows' => [
                    ['label' => __('Monthly target'), 'value' => InsightFormatter::money($monthly)],
                    ['label' => __('Current fund'), 'value' => InsightFormatter::money($balance)],
                    [
                        'label' => __('Open cycle'),
                        'value' => $postedCycle ? __('Posted') : __('Not posted'),
                    ],
                ],
                'progress' => $pct,
                'url' => MyContributionResource::getUrl('index'),
                'link_label' => __('Contributions'),
            ];

            $sixthKpi = [
                'label' => __('Of monthly'),
                'value' => $pct !== null ? $pct.'%' : '—',
                'sub' => InsightFormatter::money($monthly),
                'icon' => 'heroicon-o-chart-bar',
                'accent' => $balance < 0 ? 'rose' : ($pct !== null && $pct >= 100 ? 'emerald' : 'sky'),
            ];
        }

        return [
            'panels' => $panels,
            'sixth_kpi' => $sixthKpi,
        ];
    }

    /**
     * @return array{active_loan: ?Loan, pending_postings: int}
     */
    private function memberContextMetrics(Member $member): array
    {
        return [
            'active_loan' => Loan::query()
                ->where('member_id', $member->id)
                ->active()
                ->first(),
            'pending_postings' => (int) FundPosting::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    /**
     * @param  array{panels: list<array<string, mixed>>, sixth_kpi: ?array<string, mixed>}  $context
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        float $balance,
        float $credits30,
        float $debits30,
        float $net30,
        int $txCount30,
        int $totalTx,
        array $context,
    ): array {
        $kpis = [
            [
                'label' => __('Balance'),
                'value' => InsightFormatter::compactAmount($balance),
                'sub' => InsightFormatter::money($balance),
                'icon' => 'heroicon-o-banknotes',
                'accent' => $balance >= 0 ? 'emerald' : 'rose',
                'value_class' => $balance >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
            ],
            [
                'label' => __('Credits 30d'),
                'value' => InsightFormatter::compactAmount($credits30),
                'sub' => InsightFormatter::money($credits30),
                'icon' => 'heroicon-o-arrow-trending-up',
                'accent' => 'emerald',
            ],
            [
                'label' => __('Debits 30d'),
                'value' => InsightFormatter::compactAmount($debits30),
                'sub' => InsightFormatter::money($debits30),
                'icon' => 'heroicon-o-arrow-trending-down',
                'accent' => 'rose',
            ],
            [
                'label' => __('Net 30d'),
                'value' => ($net30 >= 0 ? '+' : '−').InsightFormatter::compactAmount(abs($net30)),
                'sub' => trans_choice(':count txn|:count txns', $txCount30, ['count' => $txCount30]),
                'icon' => 'heroicon-o-arrows-right-left',
                'accent' => $net30 >= 0 ? 'teal' : 'amber',
                'value_class' => $net30 >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-amber-600 dark:text-amber-400',
            ],
            [
                'label' => __('Ledger'),
                'value' => (string) $totalTx,
                'sub' => __('All time'),
                'icon' => 'heroicon-o-document-text',
                'accent' => 'indigo',
            ],
        ];

        $kpis[] = $context['sixth_kpi'] ?? [
            'label' => __('30d txns'),
            'value' => (string) $txCount30,
            'sub' => __('This period'),
            'icon' => 'heroicon-o-clock',
            'accent' => 'sky',
        ];

        return $kpis;
    }
}
