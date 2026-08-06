<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\ReconciliationException;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanQueueService;
use App\Support\BusinessDay;
use App\Support\CollectionInsightsCache;
use App\Support\Insights\InsightFormatter;
use App\Support\Insights\ValueChart;
use App\Support\TenantRuntimeCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lazy-loaded CSS chart payloads for admin value insights.
 * Every method is short-TTL cached; callers should only invoke when a fold is expanded.
 */
final class ValueChartsService
{
    private const RUNTIME_TTL = 120;

    public function __construct(
        protected ContributionCycleService $cycles,
        protected ContributionInsightsService $contributionInsights,
        protected LoanQueueService $loanQueue,
        protected TreasuryForecastService $treasury,
        protected LoanDelinquencyService $delinquency,
    ) {}

    /**
     * Contribution cycle: paid / partial / pending / exempt for open (or selected) cycle.
     *
     * @return array<string, mixed>
     */
    public function collectionCycleComposition(?string $cycleKey = null): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
            'value_chart:collection_cycle:'.($cycleKey ?? 'open'),
            function () use ($cycleKey): array {
                $snapshot = $this->contributionInsights->forContext('collect', $cycleKey);
                $open = $snapshot['open_period'] ?? [];
                $pipeline = $snapshot['pipeline'] ?? [];
                $amounts = $snapshot['collection_amounts'] ?? [];

                $paid = (int) ($pipeline['posted_open_period'] ?? 0);
                $pending = (int) ($pipeline['pending_open_period'] ?? 0);
                $missing = (int) ($pipeline['missing_open_period'] ?? $open['missing_members'] ?? 0);
                // Remaining unpaid members are "to collect"; pending rows are ledger drafts still open.
                $toCollect = max(0, $missing);

                $period = (string) ($open['label'] ?? __('Open cycle'));
                $rate = (float) ($open['collection_rate'] ?? 0);

                return ValueChart::donut(
                    __('Cycle collection mix'),
                    [
                        ['key' => 'paid', 'label' => __('Collected'), 'value' => $paid, 'color' => 'emerald'],
                        ['key' => 'pending', 'label' => __('Pending rows'), 'value' => $pending, 'color' => 'sky'],
                        ['key' => 'to_collect', 'label' => __('To collect'), 'value' => $toCollect, 'color' => 'amber'],
                    ],
                    $period,
                    $rate > 0 ? ((int) $rate).'%' : '—',
                ) + [
                    'money_hint' => [
                        'recovered' => (float) ($amounts['recovered'] ?? $amounts['collected'] ?? 0),
                        'unrecovered' => (float) ($amounts['unrecovered'] ?? $amounts['arrears'] ?? 0),
                    ],
                ];
            },
        );
    }

    /**
     * Overdue installment aging buckets (1–30 / 31–60 / 61–90 / 90+ days).
     *
     * @return array<string, mixed>
     */
    public function delinquencyAging(): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_DELINQUENCY,
            'value_chart:aging',
            function (): array {
                $today = BusinessDay::today();

                $installments = LoanInstallment::query()
                    ->whereIn('status', ['overdue', 'pending'])
                    ->whereDate('due_date', '<', $today->toDateString())
                    ->get(['id', 'amount', 'amount_collected', 'due_date', 'overdue_since']);

                $bucketDefs = [
                    '1-30' => ['label' => __('1–30 days'), 'color' => 'amber', 'min' => 1, 'max' => 30],
                    '31-60' => ['label' => __('31–60 days'), 'color' => 'orange', 'min' => 31, 'max' => 60],
                    '61-90' => ['label' => __('61–90 days'), 'color' => 'rose', 'min' => 61, 'max' => 90],
                    '90+' => ['label' => __('90+ days'), 'color' => 'red', 'min' => 91, 'max' => PHP_INT_MAX],
                ];

                $bucketStats = [];
                foreach (array_keys($bucketDefs) as $key) {
                    $bucketStats[$key] = ['count' => 0, 'amount' => 0.0];
                }

                foreach ($installments as $installment) {
                    $anchor = $installment->overdue_since
                        ? $installment->overdue_since->startOfDay()
                        : $installment->due_date?->copy()->startOfDay();

                    if ($anchor === null) {
                        continue;
                    }

                    $days = (int) $anchor->diffInDays($today);
                    if ($days < 1) {
                        $days = 1;
                    }

                    $key = match (true) {
                        $days <= 30 => '1-30',
                        $days <= 60 => '31-60',
                        $days <= 90 => '61-90',
                        default => '90+',
                    };

                    $remaining = max(0.0, (float) $installment->amount - (float) ($installment->amount_collected ?? 0));
                    $bucketStats[$key]['count']++;
                    $bucketStats[$key]['amount'] += $remaining;
                }

                $buckets = [];
                foreach ($bucketDefs as $key => $meta) {
                    $buckets[] = [
                        'key' => $key,
                        'label' => $meta['label'],
                        'color' => $meta['color'],
                        'count' => $bucketStats[$key]['count'],
                        'amount' => round($bucketStats[$key]['amount'], 2),
                    ];
                }

                return ValueChart::aging(
                    __('Delinquency aging'),
                    $buckets,
                    __('Open overdue / past-due installments by age'),
                );
            },
        );
    }

    /**
     * Active / overdue / queued / completed loan amounts (outstanding or principal).
     *
     * @return array<string, mixed>
     */
    public function loanPortfolioComposition(): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_LOAN_EMI,
            'value_chart:portfolio_composition',
            function (): array {
                $activeOutstanding = (float) LoanInstallment::query()
                    ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
                    ->whereIn('status', ['pending', 'overdue'])
                    ->sum(DB::raw('GREATEST(amount - COALESCE(amount_collected, 0), 0)'));

                $overdueOutstanding = (float) LoanInstallment::query()
                    ->whereIn('status', ['overdue'])
                    ->sum(DB::raw('GREATEST(amount - COALESCE(amount_collected, 0), 0)'));

                // Overdue is a slice of active; present remaining active separately for the donut.
                $currentActive = max(0.0, $activeOutstanding - $overdueOutstanding);

                $queued = (float) Loan::query()
                    ->whereIn('status', ['approved', 'partially_disbursed', 'pending'])
                    ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(amount_approved, amount_requested, amount, 0) - COALESCE(amount_disbursed, 0), 0)), 0) as remaining')
                    ->value('remaining');

                $completed = (float) Loan::query()
                    ->whereIn('status', ['completed', 'early_settled'])
                    ->sum(DB::raw('COALESCE(amount_disbursed, amount_approved, amount, 0)'));

                return ValueChart::donut(
                    __('Loan portfolio mix'),
                    [
                        ['key' => 'current', 'label' => __('Current outstanding'), 'value' => round($currentActive, 2), 'color' => 'sky'],
                        ['key' => 'overdue', 'label' => __('Overdue outstanding'), 'value' => round($overdueOutstanding, 2), 'color' => 'rose'],
                        ['key' => 'queued', 'label' => __('Queued demand'), 'value' => round((float) $queued, 2), 'color' => 'amber'],
                        ['key' => 'completed', 'label' => __('Completed (disbursed)'), 'value' => round($completed, 2), 'color' => 'emerald'],
                    ],
                    __('Where capital sits across the book'),
                    MoneyDisplay::compactWithSymbol(round($activeOutstanding + (float) $queued, 2), InsightFormatter::currency()) ?? '—',
                ) + ['as_money' => true];
            },
        );
    }

    /**
     * Master cash / fund / bank balances stacked.
     *
     * @return array<string, mixed>
     */
    public function liquidityStack(): array
    {
        return TenantRuntimeCache::remember(
            'value_chart:liquidity_stack',
            self::RUNTIME_TTL,
            function (): array {
                $cash = (float) (Account::masterCash()?->balance ?? 0);
                $fund = (float) (Account::masterFund()?->balance ?? 0);
                $bank = (float) (Account::masterBank()?->balance ?? 0);

                return ValueChart::stack(
                    __('Liquidity stack'),
                    [
                        ['key' => 'cash', 'label' => __('Master cash'), 'value' => round($cash, 2), 'color' => 'sky'],
                        ['key' => 'fund', 'label' => __('Master fund'), 'value' => round($fund, 2), 'color' => 'emerald'],
                        ['key' => 'bank', 'label' => __('Master bank'), 'value' => round($bank, 2), 'color' => 'indigo'],
                    ],
                    __('Master pool allocation'),
                ) + ['as_money' => true];
            },
        );
    }

    /**
     * Treasury levels: cash, projected, cash-out demand, clearing backlog.
     *
     * @return array<string, mixed>
     */
    public function treasuryRunway(): array
    {
        return TenantRuntimeCache::remember(
            'value_chart:treasury_runway',
            self::RUNTIME_TTL,
            function (): array {
                $s = $this->treasury->snapshot();

                return ValueChart::levels(
                    __('Treasury runway'),
                    [
                        [
                            'key' => 'cash',
                            'label' => __('Master cash'),
                            'value' => (float) $s['master_cash'],
                            'color' => 'sky',
                        ],
                        [
                            'key' => 'projected',
                            'label' => __('Projected available'),
                            'value' => (float) $s['projected_available_cash'],
                            'color' => (float) $s['projected_available_cash'] < 0 ? 'rose' : 'emerald',
                        ],
                        [
                            'key' => 'cash_outs',
                            'label' => __('Pending cash-outs'),
                            'value' => (float) $s['pending_cash_out_amount'],
                            'color' => 'amber',
                        ],
                        [
                            'key' => 'backlog',
                            'label' => __('Clearing backlog'),
                            'value' => (float) $s['clearing_backlog_amount'],
                            'color' => 'violet',
                        ],
                    ],
                    __('Coverage :pct', [
                        'pct' => $s['coverage_percent'] !== null ? $s['coverage_percent'].'%' : '—',
                    ]),
                ) + [
                    'tone' => $s['tone'],
                    'as_money' => true,
                ];
            },
        );
    }

    /**
     * Top borrowers by outstanding + top guarantors at risk (count).
     *
     * @return array<string, mixed>
     */
    public function concentrationExposure(): array
    {
        return CollectionInsightsCache::remember(
            CollectionInsightsCache::DOMAIN_MEMBERS,
            'value_chart:concentration',
            function (): array {
                $topBorrowers = Loan::query()
                    ->whereIn('status', ['active', 'transferred'])
                    ->with('member:id,name,member_number')
                    ->get(['id', 'member_id', 'amount_approved', 'amount_disbursed', 'total_repaid'])
                    ->map(function (Loan $loan): array {
                        $outstanding = max(0.0, (float) $loan->getOutstandingBalance());

                        return [
                            'key' => 'loan-'.$loan->id,
                            'label' => $loan->member?->name ?? __('Member #:id', ['id' => $loan->member_id]),
                            'value' => round($outstanding, 2),
                            'sub' => $loan->member?->member_number,
                            'color' => 'sky',
                        ];
                    })
                    ->filter(fn (array $row): bool => $row['value'] > 0.005)
                    ->sortByDesc('value')
                    ->take(8)
                    ->values()
                    ->all();

                $guarantorAtRisk = (int) ($this->delinquency->digestCounts()['guarantor_at_risk'] ?? 0);
                $guarantorTransferred = (int) ($this->delinquency->digestCounts()['guarantor_transferred'] ?? 0);

                $chart = ValueChart::bars(
                    __('Exposure concentration'),
                    $topBorrowers !== [] ? $topBorrowers : [
                        ['key' => 'none', 'label' => __('No active borrowers'), 'value' => 0, 'color' => 'slate'],
                    ],
                    __('Top open loan balances · Guarantor risk: :risk · Transferred: :xfer', [
                        'risk' => $guarantorAtRisk,
                        'xfer' => $guarantorTransferred,
                    ]),
                    asMoney: true,
                );

                return $chart + [
                    'guarantor_at_risk' => $guarantorAtRisk,
                    'guarantor_transferred' => $guarantorTransferred,
                ];
            },
        );
    }

    /**
     * Loan queue stage funnel.
     *
     * @return array<string, mixed>
     */
    public function pipelineFunnel(): array
    {
        return TenantRuntimeCache::remember(
            'value_chart:pipeline_funnel',
            self::RUNTIME_TTL,
            function (): array {
                $kpis = $this->loanQueue->kpis();

                return ValueChart::funnel(
                    __('Loan pipeline funnel'),
                    [
                        ['key' => 'intake', 'label' => __('Intake'), 'value' => (int) ($kpis['intake'] ?? 0), 'color' => 'amber'],
                        ['key' => 'queued', 'label' => __('Queued'), 'value' => (int) ($kpis['queued'] ?? 0), 'color' => 'sky'],
                        ['key' => 'process', 'label' => __('Process'), 'value' => (int) ($kpis['process'] ?? 0), 'color' => 'emerald'],
                        ['key' => 'running', 'label' => __('Running'), 'value' => (int) ($kpis['running'] ?? 0), 'color' => 'teal'],
                    ],
                    __('Queued demand :amount', [
                        'amount' => MoneyDisplay::format((float) ($kpis['queued_demand'] ?? 0), InsightFormatter::currency(), precision: 0) ?? '—',
                    ]),
                );
            },
        );
    }

    /**
     * Open recon exceptions by severity (and light domain breakdown).
     *
     * @return array<string, mixed>
     */
    public function reconExceptionMix(): array
    {
        return TenantRuntimeCache::remember(
            'value_chart:recon_mix',
            self::RUNTIME_TTL,
            function (): array {
                if (! Schema::hasTable((new ReconciliationException)->getTable())) {
                    $empty = ValueChart::donut(__('Exception mix'), [], __('No exception table'));

                    return [
                        'severity' => $empty,
                        'domain' => ValueChart::bars(__('Exceptions by domain'), [
                            ['key' => 'none', 'label' => __('No open exceptions'), 'value' => 0, 'color' => 'slate'],
                        ]),
                    ];
                }

                $bySeverity = ReconciliationException::query()
                    ->open()
                    ->selectRaw('severity, COUNT(*) as cnt')
                    ->groupBy('severity')
                    ->pluck('cnt', 'severity');

                $severityColors = [
                    'critical' => 'rose',
                    'high' => 'amber',
                    'medium' => 'sky',
                    'low' => 'slate',
                    'warning' => 'amber',
                ];

                $segments = [];
                foreach (['critical', 'high', 'medium', 'low', 'warning'] as $sev) {
                    $count = (int) ($bySeverity[$sev] ?? 0);
                    if ($count <= 0) {
                        continue;
                    }
                    $segments[] = [
                        'key' => $sev,
                        'label' => __(ucfirst($sev)),
                        'value' => $count,
                        'color' => $severityColors[$sev] ?? 'slate',
                    ];
                }

                if ($segments === []) {
                    $segments[] = ['key' => 'clear', 'label' => __('Clear'), 'value' => 1, 'color' => 'emerald'];
                }

                $byDomain = ReconciliationException::query()
                    ->open()
                    ->selectRaw('domain, COUNT(*) as cnt')
                    ->groupBy('domain')
                    ->orderByDesc('cnt')
                    ->limit(6)
                    ->pluck('cnt', 'domain');

                $domainRows = $byDomain->map(fn ($cnt, $domain): array => [
                    'key' => (string) $domain,
                    'label' => (string) $domain,
                    'value' => (int) $cnt,
                    'color' => 'indigo',
                ])->values()->all();

                $donut = ValueChart::donut(
                    __('Recon exceptions'),
                    $segments,
                    __('Open by severity'),
                    (string) array_sum(array_column($segments, 'value')),
                );

                $domainChart = ValueChart::bars(
                    __('Exceptions by domain'),
                    $domainRows !== [] ? $domainRows : [
                        ['key' => 'none', 'label' => __('No open exceptions'), 'value' => 0, 'color' => 'slate'],
                    ],
                    null,
                    asMoney: false,
                );

                return [
                    'severity' => $donut,
                    'domain' => $domainChart,
                ];
            },
        );
    }

    /**
     * Dashboard fold: liquidity + treasury in one cached bundle.
     *
     * @return array{liquidity: array<string, mixed>, treasury: array<string, mixed>}
     */
    public function dashboardBundle(): array
    {
        return TenantRuntimeCache::remember(
            'value_chart:dashboard_bundle',
            self::RUNTIME_TTL,
            fn (): array => [
                'liquidity' => $this->liquidityStack(),
                'treasury' => $this->treasuryRunway(),
            ],
        );
    }
}
