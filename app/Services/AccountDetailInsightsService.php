<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class AccountDetailInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Account $account): array
    {
        $account->loadMissing('member');
        $account = $account->fresh() ?? $account;

        $currency = InsightFormatter::currency();
        $balance = (float) $account->balance;
        $since = Carbon::now()->subDays(30);

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

        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::now()->subDays($i)->startOfDay();
            $sparkline[] = Transaction::query()
                ->where('account_id', $account->id)
                ->whereDate('transacted_at', $day)
                ->count();
        }

        $recent = Transaction::query()
            ->where('account_id', $account->id)
            ->with('member')
            ->orderByDesc('transacted_at')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'transacted_at' => $transaction->transacted_at?->format('M j, H:i'),
                'type' => $transaction->type,
                'amount' => InsightFormatter::money((float) $transaction->amount),
                'signed_class' => $transaction->type === 'credit'
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
                'description' => Str::limit($transaction->description ?? '—', 48),
                'member' => $transaction->member?->name,
                'is_reversal' => $transaction->reference_type === Transaction::class,
            ])
            ->all();

        $typeLabel = $account->is_master
            ? MasterAccountResource::tabLabel($account->type)
            : match ($account->type) {
                'cash' => __('Cash'),
                'fund' => __('Fund'),
                default => ucfirst($account->type),
            };

        $context = $this->buildContext($account, $balance);
        $kpis = $this->buildKpis($account, $balance, $credits30, $debits30, $net30, $txCount30, $totalTx, $context);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'type_label' => $typeLabel,
                'is_master' => $account->is_master,
            ],
            'currency' => $currency,
            'balance' => $balance,
            'balance_display' => InsightFormatter::money($balance),
            'balance_negative' => $balance < 0,
            'credits30' => $credits30,
            'debits30' => $debits30,
            'net30' => $net30,
            'tx_count30' => $txCount30,
            'total_tx' => $totalTx,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'recent' => $recent,
            'hero' => $this->buildHero($account, $balance, $context),
            'kpis' => $kpis,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Account $account, float $balance): array
    {
        $context = [
            'panels' => [],
            'sixth_kpi' => null,
        ];

        if ($account->is_master) {
            return match ($account->type) {
                'cash' => $this->masterCashContext($balance),
                'fund' => $this->masterFundContext($balance),
                'bank' => $this->masterBankContext($balance),
                'expense' => $this->masterReserveContext($account, $balance, 'expense'),
                'invest' => $this->masterInvestContext($account, $balance),
                'fees' => $this->masterFeesContext($balance),
                default => $context,
            };
        }

        return $this->memberAccountContext($account, $balance);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberAccountContext(Account $account, float $balance): array
    {
        $member = $account->member;

        if ($member === null) {
            return ['panels' => [], 'sixth_kpi' => null];
        }

        $member->loadMissing(['cashAccount', 'fundAccount']);

        $activeLoan = Loan::query()
            ->where('member_id', $member->id)
            ->active()
            ->first();

        $outstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;
        $pendingPostings = FundPosting::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->count();

        $panels = [
            [
                'title' => __('Member'),
                'rows' => [
                    ['label' => __('Name'), 'value' => $member->name],
                    ['label' => __('Number'), 'value' => $member->member_number],
                    ['label' => __('Status'), 'value' => Member::statusOptions()[$member->status] ?? $member->status],
                ],
                'url' => MemberResource::getUrl('edit', ['record' => $member]),
                'link_label' => __('View member'),
            ],
        ];

        $sixthKpi = null;

        if ($account->type === 'cash') {
            $fundBalance = (float) ($member->fundAccount?->balance ?? 0);
            $panels[] = [
                'title' => __('Related balances'),
                'rows' => [
                    ['label' => __('Fund account'), 'value' => InsightFormatter::money($fundBalance)],
                    ['label' => __('Loan outstanding'), 'value' => InsightFormatter::money($outstanding)],
                    ['label' => __('Pending postings'), 'value' => (string) $pendingPostings],
                ],
                'url' => $activeLoan ? LoanResource::getUrl('edit', ['record' => $activeLoan]) : null,
                'link_label' => $activeLoan ? __('View loan') : null,
            ];

            $sixthKpi = [
                'key' => 'postings',
                'label' => __('Postings'),
                'value' => (string) $pendingPostings,
                'sub' => __('Pending'),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'accent' => $pendingPostings > 0 ? 'amber' : 'teal',
            ];
        }

        if ($account->type === 'fund') {
            $min = (float) $member->monthly_contribution_amount;
            $pct = $min > 0 ? min(100, round(($balance / $min) * 100, 1)) : null;
            $panels[] = [
                'title' => __('Fund progress'),
                'rows' => [
                    ['label' => __('Monthly minimum'), 'value' => InsightFormatter::money($min)],
                    ['label' => __('Current fund'), 'value' => InsightFormatter::money($balance)],
                    ['label' => __('Of minimum'), 'value' => $pct !== null ? $pct.'%' : '—'],
                ],
                'progress' => $pct,
            ];

            $sixthKpi = [
                'key' => 'minimum',
                'label' => __('Of minimum'),
                'value' => $pct !== null ? $pct.'%' : '—',
                'sub' => InsightFormatter::money($min),
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
     * @return array<string, mixed>
     */
    private function masterCashContext(float $balance): array
    {
        $pendingBank = BankTransaction::query()->whereIn('status', ['imported', 'mirrored'])->count();
        $pendingPostings = FundPosting::query()->where('status', 'pending')->count();

        return [
            'panels' => [
                [
                    'title' => __('Operations queue'),
                    'rows' => [
                        ['label' => __('Bank to post'), 'value' => (string) $pendingBank],
                        ['label' => __('Deposits'), 'value' => (string) $pendingPostings],
                        ['label' => __('On hand'), 'value' => InsightFormatter::money($balance)],
                    ],
                    'url' => BankAccountsResource::getUrl('index', ['tab' => 'imports']),
                    'link_label' => __('Bank transactions'),
                ],
            ],
            'sixth_kpi' => [
                'key' => 'queue',
                'label' => __('To post'),
                'value' => (string) $pendingBank,
                'sub' => __('Bank lines'),
                'icon' => 'heroicon-o-clock',
                'accent' => $pendingBank > 0 ? 'amber' : 'emerald',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function masterFundContext(float $balance): array
    {
        $loanExposure = (float) Loan::active()->get()->sum(
            fn (Loan $loan): float => $loan->getOutstandingBalance()
        );
        $coverage = $loanExposure > 0.01 ? round($balance / $loanExposure, 2) : null;
        $coveragePercent = $coverage !== null ? min(100, round($coverage * 100, 1)) : 100;

        return [
            'panels' => [
                [
                    'title' => __('Fund coverage'),
                    'rows' => [
                        ['label' => __('Master fund'), 'value' => InsightFormatter::money($balance)],
                        ['label' => __('Loan exposure'), 'value' => InsightFormatter::money($loanExposure)],
                        ['label' => __('Coverage'), 'value' => $coverage !== null ? $coverage.'×' : '—'],
                    ],
                    'progress' => $coveragePercent,
                    'url' => LoanResource::getUrl('index'),
                    'link_label' => __('Loans'),
                ],
            ],
            'sixth_kpi' => [
                'key' => 'coverage',
                'label' => __('Coverage'),
                'value' => $coverage !== null ? $coverage.'×' : '—',
                'sub' => InsightFormatter::compactAmount($loanExposure).' '.__('exposure'),
                'icon' => 'heroicon-o-shield-check',
                'accent' => $coverage !== null && $coverage >= 1 ? 'emerald' : 'amber',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function masterBankContext(float $balance): array
    {
        $masterCash = (float) (Account::masterCash()?->balance ?? 0);

        return [
            'panels' => [
                [
                    'title' => __('Cash alignment'),
                    'rows' => [
                        ['label' => __('Master bank'), 'value' => InsightFormatter::money($balance)],
                        ['label' => __('Master cash'), 'value' => InsightFormatter::money($masterCash)],
                    ],
                    'url' => Account::masterCash()
                        ? MasterAccountResource::getUrl('view', ['record' => Account::masterCash()])
                        : null,
                    'link_label' => __('Master cash'),
                ],
            ],
            'sixth_kpi' => [
                'key' => 'cash',
                'label' => __('Master cash'),
                'value' => InsightFormatter::compactAmount($masterCash),
                'sub' => __('Ledger'),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'sky',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function masterReserveContext(Account $account, float $balance, string $type): array
    {
        $fundedIn = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'credit')
            ->where('description', 'like', '%(reserve funding)%')
            ->sum('amount');

        $disbursedOut = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'debit')
            ->where('description', 'like', '%(to master cash)%')
            ->sum('amount');

        return [
            'panels' => [
                [
                    'title' => __('Reserve flow'),
                    'rows' => [
                        ['label' => __('Funded in'), 'value' => InsightFormatter::money($fundedIn)],
                        ['label' => __('Disbursed out'), 'value' => InsightFormatter::money($disbursedOut)],
                        ['label' => __('Available'), 'value' => InsightFormatter::money($balance)],
                    ],
                ],
            ],
            'sixth_kpi' => [
                'key' => 'available',
                'label' => __('Available'),
                'value' => InsightFormatter::compactAmount($balance),
                'sub' => __('Balance'),
                'icon' => 'heroicon-o-wallet',
                'accent' => $balance > 0 ? 'emerald' : 'amber',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function masterInvestContext(Account $account, float $balance): array
    {
        $investedOut = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'debit')
            ->where('description', 'like', '%(to master cash)%')
            ->sum('amount');

        $returnsIn = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'credit')
            ->where('description', 'like', '%(investment return)%')
            ->sum('amount');

        $netReturn = $returnsIn - $investedOut;

        return [
            'panels' => [
                [
                    'title' => __('Investment activity'),
                    'rows' => [
                        ['label' => __('Returns in'), 'value' => InsightFormatter::money($returnsIn)],
                        ['label' => __('Invested out'), 'value' => InsightFormatter::money($investedOut)],
                        ['label' => __('Net return'), 'value' => InsightFormatter::money($netReturn)],
                    ],
                ],
            ],
            'sixth_kpi' => [
                'key' => 'return',
                'label' => __('Net return'),
                'value' => ($netReturn >= 0 ? '+' : '−').InsightFormatter::compactAmount($netReturn),
                'sub' => __('Lifetime'),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'accent' => $netReturn >= 0 ? 'emerald' : 'rose',
                'value_class' => $netReturn >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function masterFeesContext(float $balance): array
    {
        return [
            'panels' => [
                [
                    'title' => __('Fees reserve'),
                    'rows' => [
                        ['label' => __('Held in fees'), 'value' => InsightFormatter::money($balance)],
                    ],
                    'url' => Account::masterCash()
                        ? MasterAccountResource::getUrl('view', ['record' => Account::masterCash()])
                        : null,
                    'link_label' => __('Master cash'),
                ],
            ],
            'sixth_kpi' => [
                'key' => 'held',
                'label' => __('Held'),
                'value' => InsightFormatter::compactAmount($balance),
                'sub' => __('Fees'),
                'icon' => 'heroicon-o-receipt-percent',
                'accent' => 'violet',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHero(Account $account, float $balance, array $context): array
    {
        if ($account->type === 'fund' && ! $account->is_master && $balance < 0) {
            return [
                'tone' => 'warning',
                'title' => __('Fund is below zero'),
                'subtitle' => __('Negative fund balance usually indicates an active loan allocation against this member.'),
            ];
        }

        if ($account->is_master && $account->type === 'cash') {
            $pending = BankTransaction::query()->whereIn('status', ['imported', 'mirrored'])->count();

            if ($pending > 0) {
                return [
                    'tone' => 'warning',
                    'title' => __('Bank lines awaiting posting'),
                    'subtitle' => trans_choice(':count transaction needs ledger posting|:count transactions need ledger posting', $pending, ['count' => $pending]),
                    'cta_label' => __('Bank queue'),
                    'cta_url' => BankAccountsResource::getUrl('index', ['tab' => 'imports']),
                ];
            }
        }

        if (! $account->is_master && $account->type === 'cash' && $balance <= 0) {
            $pending = FundPosting::query()
                ->where('member_id', $account->member_id)
                ->where('status', 'pending')
                ->count();

            return [
                'tone' => 'amber',
                'title' => __('No available cash'),
                'subtitle' => $pending > 0
                    ? __(':count deposit pending approval.', ['count' => $pending])
                    : __('Cash balance is zero or negative.'),
                'cta_label' => $pending > 0 ? __('Deposits') : null,
                'cta_url' => $pending > 0 ? FundPostingResource::getUrl('index') : null,
            ];
        }

        return [
            'tone' => $balance >= 0 ? 'success' : 'warning',
            'title' => $account->name,
            'subtitle' => $account->is_master
                ? __('Master :type ledger', ['type' => MasterAccountResource::tabLabel($account->type)])
                : __('Member :type account', [
                    'type' => match ($account->type) {
                        'cash' => __('cash'),
                        'fund' => __('fund'),
                        default => $account->type,
                    },
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        Account $account,
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
                'key' => 'balance',
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
                'key' => 'credits',
                'label' => __('Credits 30d'),
                'value' => InsightFormatter::compactAmount($credits30),
                'sub' => InsightFormatter::money($credits30),
                'icon' => 'heroicon-o-arrow-trending-up',
                'accent' => 'emerald',
            ],
            [
                'key' => 'debits',
                'label' => __('Debits 30d'),
                'value' => InsightFormatter::compactAmount($debits30),
                'sub' => InsightFormatter::money($debits30),
                'icon' => 'heroicon-o-arrow-trending-down',
                'accent' => 'rose',
            ],
            [
                'key' => 'net',
                'label' => __('Net 30d'),
                'value' => ($net30 >= 0 ? '+' : '−').InsightFormatter::compactAmount($net30),
                'sub' => trans_choice(':count txn|:count txns', $txCount30, ['count' => $txCount30]),
                'icon' => 'heroicon-o-arrows-right-left',
                'accent' => $net30 >= 0 ? 'teal' : 'amber',
                'value_class' => $net30 >= 0
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-amber-600 dark:text-rose-400',
            ],
            [
                'key' => 'ledger',
                'label' => __('Ledger'),
                'value' => (string) $totalTx,
                'sub' => __('All time'),
                'icon' => 'heroicon-o-document-text',
                'accent' => 'indigo',
            ],
        ];

        $kpis[] = $context['sixth_kpi'] ?? [
            'key' => 'activity',
            'label' => __('30d txns'),
            'value' => (string) $txCount30,
            'sub' => __('This period'),
            'icon' => 'heroicon-o-clock',
            'accent' => 'sky',
        ];

        return $kpis;
    }
}
