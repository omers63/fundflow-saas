<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\Lang;
use App\Support\PublicPageSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

final class TenantDashboardService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanInsightsService $loanInsights,
        protected MasterAccountsInsightsService $masterAccounts,
        protected BankAccountsInsightsService $bankAccounts,
        protected LoanDelinquencyService $delinquency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $now = BusinessDay::now();
        $currency = InsightFormatter::currency();
        $user = Auth::user();
        assert($user instanceof User);

        $masters = Account::master()->get()->keyBy('type');
        $masterBalance = fn (string $type): float => (float) ($masters->get($type)?->balance ?? 0);

        $loanPortfolio = $this->loanInsights->portfolioSnapshot();
        $masterSnapshot = $this->masterAccounts->snapshot();
        $bankSnapshot = $this->bankAccounts->snapshot();
        $delinquencyCounts = $this->delinquency->digestCounts();

        $activeMembers = Member::active()->count();
        $pendingContributions = Contribution::pending()->count();
        $pendingDeposits = FundPosting::query()->where('status', 'pending')->count();
        $pendingApplications = MembershipApplication::query()->where('status', 'pending')->count();
        $loanQueueCount = Loan::query()->inQueue()->count();
        $pendingEligibilityReviews = LoanEligibilityOverrideRequest::isTableReady()
            ? LoanEligibilityOverrideRequest::pending()->count()
            : 0;
        $openReconciliationCount = $this->openReconciliationCount();
        $attentionTotal = $pendingContributions + $pendingDeposits + $pendingApplications + $loanQueueCount
            + $pendingEligibilityReviews
            + ($delinquencyCounts['overdue_installments'] ?? 0)
            + ($delinquencyCounts['contribution_arrears_periods'] ?? 0)
            + $openReconciliationCount;

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $openPeriodLabel = $this->cycles->periodLabel($openMonth, $openYear);
        $collectionGauge = $this->contributionCollectionGauge($openMonth, $openYear, $activeMembers);

        return [
            'currency' => $currency,
            'greeting' => $this->greeting($user, $now, $attentionTotal),
            'kpi_stats' => $this->kpiStats(
                $activeMembers,
                $pendingApplications,
                $collectionGauge,
                $loanPortfolio,
                $loanQueueCount,
                $openReconciliationCount,
            ),
            'quick_actions' => $this->quickActions(
                $pendingContributions,
                $pendingDeposits,
                $pendingApplications,
                $loanQueueCount,
                $delinquencyCounts,
                $openPeriodLabel,
            ),
            'balances' => $this->balances($masters, $masterBalance, $currency),
            'gauges' => [
                $this->fundCoverageGauge($masterSnapshot),
                $collectionGauge,
                $this->bankPostGauge($bankSnapshot),
                $this->loanHealthGauge($loanPortfolio, $delinquencyCounts),
            ],
            'attention_cards' => $this->attentionCards(
                $pendingDeposits,
                $pendingApplications,
                $loanQueueCount,
                $pendingEligibilityReviews,
                $delinquencyCounts,
                $bankSnapshot,
                $openReconciliationCount,
            ),
            'contribution_trend' => $this->contributionTrend($now),
            'loan_trend' => $this->loanInsights->sixMonthLoanVolumeTrend(),
            'loan_pipeline' => $loanPortfolio['pipeline'] ?? [],
            'workspace_sections' => $this->workspaceSections(),
            'sparkline' => $masterSnapshot['sparkline'] ?? [],
            'sparkline_max' => $masterSnapshot['sparkline_max'] ?? 1,
            'open_period_label' => $openPeriodLabel,
            'loan_queue_preview' => $this->loanQueuePreview(),
            'recent_activity' => $this->recentActivity(),
            'collection_breakdown' => $this->collectionBreakdown($openMonth, $openYear, $activeMembers, $delinquencyCounts),
            'fund_tier_utilisation' => $this->fundTierUtilisation(),
            'pool_health' => $this->poolHealth($masterBalance),
        ];
    }

    /**
     * @param  callable(string): float  $masterBalance
     * @return array<string, mixed>
     */
    private function poolHealth(callable $masterBalance): array
    {
        $masterCash = $masterBalance('cash');
        $masterFund = $masterBalance('fund');
        $memberCash = (float) Account::query()->where('is_master', false)->where('type', 'cash')->sum('balance');
        $memberFund = (float) Account::query()->where('is_master', false)->where('type', 'fund')->sum('balance');
        $cashDrift = round(abs($masterCash - $memberCash), 2);
        $fundDrift = round(abs($masterFund - $memberFund), 2);
        $hasDrift = $cashDrift > 0.01 || $fundDrift > 0.01;
        $loanExposure = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->sum('amount');
        $poolTotal = $masterCash + $masterFund;
        $solvency = $loanExposure > 0.01 ? round($poolTotal / $loanExposure, 2) : null;
        $sparkline = $this->poolHealthSparkline($poolTotal);

        return [
            'master_cash' => $masterCash,
            'master_fund' => $masterFund,
            'member_cash' => $memberCash,
            'member_fund' => $memberFund,
            'cash_drift' => $cashDrift,
            'fund_drift' => $fundDrift,
            'has_drift' => $hasDrift,
            'loan_exposure' => $loanExposure,
            'pool_total' => $poolTotal,
            'solvency_ratio' => $solvency,
            'reconciliation_url' => ReconciliationOverviewPage::getUrl(),
            'sparkline' => $sparkline['values'],
            'sparkline_max' => $sparkline['max'],
            'sparkline_start' => $sparkline['start'],
            'sparkline_end' => $sparkline['end'],
        ];
    }

    /**
     * End-of-day master cash + fund totals for the last 30 calendar days (oldest → newest).
     *
     * @return array{values: list<float>, max: float, start: float, end: float}
     */
    private function poolHealthSparkline(float $currentPoolTotal): array
    {
        $now = BusinessDay::now();
        $windowStart = $now->copy()->subDays(29)->startOfDay();

        $accountIds = Account::query()
            ->where('is_master', true)
            ->whereIn('type', ['cash', 'fund'])
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            $flat = array_fill(0, 30, round($currentPoolTotal, 2));

            return [
                'values' => $flat,
                'max' => max(1.0, $currentPoolTotal),
                'start' => $flat[0],
                'end' => $flat[29],
            ];
        }

        /** @var array<string, float> $dailyNet */
        $dailyNet = [];

        Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('transacted_at', '>=', $windowStart)
            ->get(['type', 'amount', 'transacted_at'])
            ->each(function (Transaction $transaction) use (&$dailyNet): void {
                if ($transaction->transacted_at === null) {
                    return;
                }

                $day = Carbon::parse((string) $transaction->transacted_at)->toDateString();
                $signed = $transaction->type === 'credit'
                    ? (float) $transaction->amount
                    : -(float) $transaction->amount;
                $dailyNet[$day] = ($dailyNet[$day] ?? 0.0) + $signed;
            });

        $values = [];
        $pool = $currentPoolTotal;

        for ($offset = 0; $offset < 30; $offset++) {
            $day = $now->copy()->subDays($offset)->toDateString();
            $values[29 - $offset] = round($pool, 2);
            $pool -= $dailyNet[$day] ?? 0.0;
        }

        ksort($values);

        $values = array_values($values);

        return [
            'values' => $values,
            'max' => max(1.0, max($values)),
            'start' => $values[0],
            'end' => $values[array_key_last($values)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function greeting(User $user, Carbon $now, int $attentionTotal): array
    {
        $hour = (int) $now->format('G');
        $periodKey = match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default => 'evening',
        };

        $periodLabel = match ($periodKey) {
            'morning' => Lang::ui('Good morning'),
            'afternoon' => Lang::ui('Good afternoon'),
            default => Lang::ui('Good evening'),
        };

        $fundName = PublicPageSettings::fundName(tenant('name'));

        $subtitle = match (true) {
            $attentionTotal > 0 => Lang::uiText(trans_choice(
                ':count item needs your attention today|:count items need your attention today',
                $attentionTotal,
                ['count' => $attentionTotal],
            )),
            default => Lang::ui('Your fund overview is up to date — explore the workspace below.'),
        };

        return [
            'period' => $periodKey,
            'period_label' => $periodLabel,
            'name' => $user->name,
            'fund_name' => $fundName,
            'date' => $now->locale(app()->getLocale())->translatedFormat('l, F j'),
            'subtitle' => $subtitle,
            'attention_total' => $attentionTotal,
        ];
    }

    /**
     * @param  Collection<string, Account>  $masters
     * @return list<array<string, mixed>>
     */
    private function balances($masters, callable $masterBalance, string $currency): array
    {
        $items = [
            ['type' => 'cash', 'label' => Lang::ui('Master Cash'), 'icon' => 'heroicon-o-banknotes', 'gradient' => 'from-sky-500 to-blue-600'],
            ['type' => 'fund', 'label' => Lang::ui('Master Fund'), 'icon' => 'heroicon-o-building-library', 'gradient' => 'from-emerald-500 to-teal-600'],
            ['type' => 'bank', 'label' => Lang::ui('Master Bank'), 'icon' => 'heroicon-o-building-office-2', 'gradient' => 'from-indigo-500 to-violet-600'],
        ];

        return collect($items)->map(function (array $item) use ($masters, $masterBalance): array {
            $account = $masters->get($item['type']);

            return [
                ...$item,
                'amount' => $masterBalance($item['type']),
                'url' => $account
                    ? MasterAccountResource::getUrl('view', ['record' => $account])
                    : MasterAccountResource::getUrl('index'),
            ];
        })->all();
    }

    /**
     * @param  array<string, int>  $delinquencyCounts
     * @return list<array<string, mixed>>
     */
    private function quickActions(
        int $pendingContributions,
        int $pendingDeposits,
        int $pendingApplications,
        int $loanQueueCount,
        array $delinquencyCounts,
        string $openPeriodLabel,
    ): array {
        $delinquencyTotal = ($delinquencyCounts['overdue_installments'] ?? 0)
            + ($delinquencyCounts['contribution_arrears_periods'] ?? 0)
            + ($delinquencyCounts['guarantor_at_risk'] ?? 0);

        return Lang::formatLabeledRows([
            [
                'label' => Lang::ui('Contribution cycle'),
                'description' => Lang::uiText($openPeriodLabel),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'url' => ContributionResource::listTabUrl('collect'),
                'tone' => 'cycle',
                'badge' => $pendingContributions > 0 ? (string) $pendingContributions : null,
            ],
            [
                'label' => Lang::ui('Loan queue'),
                'description' => Lang::ui('Decisions & disbursement'),
                'icon' => 'heroicon-o-queue-list',
                'url' => LoanResource::getUrl('queue'),
                'tone' => 'queue',
                'badge' => $loanQueueCount > 0 ? (string) $loanQueueCount : null,
            ],
            [
                'label' => Lang::ui('Delinquency'),
                'description' => Lang::ui('Arrears & overdue'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'url' => LoanResource::listTabUrl('overdue_installments'),
                'tone' => 'delinquency',
                'badge' => $delinquencyTotal > 0 ? (string) $delinquencyTotal : null,
            ],
            [
                'label' => Lang::ui('Deposits'),
                'description' => Lang::ui('Pending member deposits'),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'url' => FundPostingResource::getUrl('index'),
                'tone' => 'deposits',
                'badge' => $pendingDeposits > 0 ? (string) $pendingDeposits : null,
            ],
            [
                'label' => Lang::ui('Applications'),
                'description' => Lang::ui('Membership intake'),
                'icon' => 'heroicon-o-user-plus',
                'url' => MembershipApplicationResource::getUrl('index'),
                'tone' => 'applications',
                'badge' => $pendingApplications > 0 ? (string) $pendingApplications : null,
            ],
            [
                'label' => Lang::ui('Bank workspace'),
                'description' => Lang::ui('Imports & reconciliation'),
                'icon' => 'heroicon-o-building-library',
                'url' => BankAccountsResource::getUrl('index'),
                'tone' => 'bank',
                'badge' => null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $masterSnapshot
     * @return array<string, mixed>
     */
    private function fundCoverageGauge(array $masterSnapshot): array
    {
        $percent = min(100, max(0, (float) ($masterSnapshot['coverage_percent'] ?? 100)));
        $tone = match ($masterSnapshot['fund_health'] ?? 'healthy') {
            'action' => 'rose',
            'monitor' => 'amber',
            default => 'emerald',
        };

        return Lang::formatLabeledRow([
            'id' => 'coverage',
            'label' => Lang::ui('Fund coverage'),
            'value' => InsightFormatter::percent($percent, 0),
            'percent' => $percent,
            'sub' => $masterSnapshot['active_loan_count'] > 0
                ? Lang::ui(':count active loans', ['count' => $masterSnapshot['active_loan_count']])
                : Lang::ui('No active loans'),
            'tone' => $tone,
            'url' => $masterSnapshot['urls']['index'] ?? MasterAccountResource::getUrl('index'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contributionCollectionGauge(int $month, int $year, int $activeMembers): array
    {
        if ($activeMembers === 0) {
            return Lang::formatLabeledRow([
                'id' => 'contributions',
                'label' => Lang::ui('Open period'),
                'value' => '—',
                'percent' => 0,
                'sub' => Lang::ui('No active members'),
                'tone' => 'sky',
                'url' => ContributionResource::listTabUrl('collect'),
            ]);
        }

        $periodDate = Contribution::periodDate($month, $year);
        $posted = (int) Contribution::query()
            ->where('period', $periodDate)
            ->where('status', 'posted')
            ->selectRaw('COUNT(DISTINCT member_id) as aggregate')
            ->value('aggregate');

        $percent = min(100, round(($posted / $activeMembers) * 100, 1));

        return Lang::formatLabeledRow([
            'id' => 'contributions',
            'label' => Lang::ui('Open period'),
            'value' => InsightFormatter::percent($percent, 0),
            'percent' => $percent,
            'sub' => Lang::ui(':posted of :total posted', ['posted' => $posted, 'total' => $activeMembers]),
            'tone' => $percent >= 85 ? 'emerald' : ($percent >= 50 ? 'amber' : 'rose'),
            'url' => ContributionResource::listTabUrl('collect'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $bankSnapshot
     * @return array<string, mixed>
     */
    private function bankPostGauge(array $bankSnapshot): array
    {
        $percent = min(100, max(0, (float) ($bankSnapshot['post_rate'] ?? 0)));

        return Lang::formatLabeledRow([
            'id' => 'bank',
            'label' => Lang::ui('Bank posted'),
            'value' => InsightFormatter::percent($percent, 0),
            'percent' => $percent,
            'sub' => Lang::ui('Reconciliation rate'),
            'tone' => $percent >= 80 ? 'emerald' : ($percent >= 50 ? 'sky' : 'amber'),
            'url' => BankAccountsResource::getUrl('index'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $loanPortfolio
     * @param  array<string, int>  $delinquencyCounts
     * @return array<string, mixed>
     */
    private function loanHealthGauge(array $loanPortfolio, array $delinquencyCounts): array
    {
        $overdue = (int) ($delinquencyCounts['overdue_installments'] ?? 0);
        $active = (int) ($loanPortfolio['pipeline']['active'] ?? 0);
        $health = $active === 0 ? 100 : max(0, min(100, round((1 - ($overdue / max(1, $active * 3))) * 100)));

        return Lang::formatLabeledRow([
            'id' => 'loans',
            'label' => Lang::ui('Loan health'),
            'value' => InsightFormatter::percent($health, 0),
            'percent' => $health,
            'sub' => $overdue > 0
                ? Lang::uiText(trans_choice(':count overdue installment|:count overdue installments', $overdue, ['count' => $overdue]))
                : Lang::ui('Portfolio on track'),
            'tone' => $health >= 75 ? 'emerald' : ($health >= 50 ? 'amber' : 'rose'),
            'url' => LoanResource::getUrl('index'),
        ]);
    }

    private function openReconciliationCount(): int
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return 0;
        }

        try {
            return (int) ReconciliationException::query()->open()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function attentionCards(
        int $pendingDeposits,
        int $pendingApplications,
        int $loanQueueCount,
        int $pendingEligibilityReviews,
        array $delinquencyCounts,
        array $bankSnapshot,
        int $openReconciliationCount,
    ): array {
        $cards = [];

        if ($pendingEligibilityReviews > 0) {
            $cards[] = [
                'title' => Lang::ui('Eligibility reviews'),
                'body' => Lang::uiText(trans_choice(
                    ':count member eligibility review pending|:count member eligibility reviews pending',
                    $pendingEligibilityReviews,
                    ['count' => $pendingEligibilityReviews],
                )),
                'tone' => 'amber',
                'icon' => 'heroicon-o-shield-exclamation',
                'url' => LoanEligibilityOverrideRequestResource::listUrl([
                    'status' => ['value' => 'pending'],
                ]),
            ];
        }

        if ($openReconciliationCount > 0) {
            $cards[] = [
                'title' => Lang::ui('Reconciliation'),
                'body' => Lang::uiText(trans_choice(
                    ':count open exception|:count open exceptions',
                    $openReconciliationCount,
                    ['count' => $openReconciliationCount],
                )),
                'tone' => 'rose',
                'icon' => 'heroicon-o-shield-exclamation',
                'url' => ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']),
            ];
        }

        if ($loanQueueCount > 0) {
            $cards[] = [
                'title' => Lang::ui('Loan queue'),
                'body' => Lang::uiText(trans_choice(':count loan awaiting action|:count loans awaiting action', $loanQueueCount, ['count' => $loanQueueCount])),
                'tone' => 'amber',
                'icon' => 'heroicon-o-queue-list',
                'url' => LoanResource::getUrl('queue'),
            ];
        }

        if ($pendingApplications > 0) {
            $cards[] = [
                'title' => Lang::ui('Membership applications'),
                'body' => Lang::uiText(trans_choice(':count pending review|:count pending reviews', $pendingApplications, ['count' => $pendingApplications])),
                'tone' => 'sky',
                'icon' => 'heroicon-o-user-plus',
                'url' => MembershipApplicationResource::getUrl('index'),
            ];
        }

        if ($pendingDeposits > 0) {
            $cards[] = [
                'title' => Lang::ui('Deposits'),
                'body' => Lang::uiText(trans_choice(':count deposit to accept|:count deposits to accept', $pendingDeposits, ['count' => $pendingDeposits])),
                'tone' => 'violet',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'url' => FundPostingResource::getUrl('index'),
            ];
        }

        $overdue = (int) ($delinquencyCounts['overdue_installments'] ?? 0);
        if ($overdue > 0) {
            $cards[] = [
                'title' => Lang::ui('Delinquency'),
                'body' => Lang::uiText(trans_choice(':count overdue installment|:count overdue installments', $overdue, ['count' => $overdue])),
                'tone' => 'rose',
                'icon' => 'heroicon-o-exclamation-triangle',
                'url' => LoanResource::listTabUrl('overdue_installments'),
            ];
        }

        $pendingBank = (int) ($bankSnapshot['pending_post'] ?? 0);
        if ($pendingBank > 0) {
            $cards[] = [
                'title' => Lang::ui('Bank imports'),
                'body' => Lang::uiText(trans_choice(':count transaction to post|:count transactions to post', $pendingBank, ['count' => $pendingBank])),
                'tone' => 'indigo',
                'icon' => 'heroicon-o-document-arrow-up',
                'url' => BankAccountsResource::getUrl('index'),
            ];
        }

        if ($cards === []) {
            $cards[] = [
                'title' => Lang::ui('All clear'),
                'body' => Lang::ui('No urgent queues — review trends and member activity below.'),
                'tone' => 'emerald',
                'icon' => 'heroicon-o-check-badge',
                'url' => MemberResource::getUrl('index'),
            ];
        }

        return array_slice(Lang::formatLabeledRows($cards), 0, 4);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contributionTrend(Carbon $now): array
    {
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        Contribution::query()
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['posted_at', 'amount'])
            ->each(function (Contribution $contribution) use (&$monthTotals): void {
                $postedAt = $contribution->posted_at;

                if ($postedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $postedAt)->startOfMonth()->format('Y-m');
                $monthTotals[$key] ??= ['amount' => 0.0, 'count' => 0];
                $monthTotals[$key]['amount'] += (float) $contribution->amount;
                $monthTotals[$key]['count']++;
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $posted = (float) ($monthTotals[$key]['amount'] ?? 0.0);
            $count = (int) ($monthTotals[$key]['count'] ?? 0);

            $trend[] = [
                'label' => $month->locale(app()->getLocale())->translatedFormat('M'),
                'amount' => $posted,
                'count' => $count,
                'amount_formatted' => InsightFormatter::money($posted),
            ];
        }

        return $trend;
    }

    /**
     * Four headline KPI cards for the dashboard strip.
     *
     * @param  array<string, mixed>  $collectionGauge
     * @param  array<string, mixed>  $loanPortfolio
     * @return list<array<string, mixed>>
     */
    private function kpiStats(
        int $activeMembers,
        int $pendingApplications,
        array $collectionGauge,
        array $loanPortfolio,
        int $loanQueueCount,
        int $openReconciliationCount,
    ): array {
        $activeLoans = (int) ($loanPortfolio['pipeline']['active'] ?? 0);

        return Lang::formatLabeledRows([
            [
                'label' => Lang::ui('Active members'),
                'value' => (string) $activeMembers,
                'sub' => $pendingApplications > 0
                    ? Lang::uiText(trans_choice('+:n pending application|+:n pending applications', $pendingApplications, ['n' => $pendingApplications]))
                    : Lang::ui('No pending applications'),
                'sub_tone' => $pendingApplications > 0 ? 'amber' : 'success',
                'icon' => 'heroicon-o-users',
                'url' => MemberResource::getUrl('index'),
            ],
            [
                'label' => Lang::ui('Collected this cycle'),
                'value' => $collectionGauge['value'],
                'sub' => $collectionGauge['sub'],
                'sub_tone' => $collectionGauge['tone'],
                'icon' => 'heroicon-o-arrow-path-rounded-square',
                'url' => ContributionResource::listTabUrl('collect'),
            ],
            [
                'label' => Lang::ui('Active loans'),
                'value' => (string) $activeLoans,
                'sub' => $loanQueueCount > 0
                    ? Lang::uiText(trans_choice(':n in queue|:n in queue', $loanQueueCount, ['n' => $loanQueueCount]))
                    : Lang::ui('Queue clear'),
                'sub_tone' => $loanQueueCount > 0 ? 'amber' : 'success',
                'icon' => 'heroicon-o-banknotes',
                'url' => LoanResource::getUrl('index'),
            ],
            [
                'label' => Lang::ui('Recon exceptions'),
                'value' => (string) $openReconciliationCount,
                'sub' => $openReconciliationCount > 0
                    ? Lang::ui('Require action')
                    : Lang::ui('All clear'),
                'sub_tone' => $openReconciliationCount > 0 ? 'danger' : 'success',
                'icon' => 'heroicon-o-shield-exclamation',
                'url' => ReconciliationOverviewPage::getUrl(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loanQueuePreview(): array
    {
        return Loan::query()
            ->inQueue()
            ->with('member')
            ->orderByDesc('is_emergency')
            ->orderBy('queue_position')
            ->limit(5)
            ->get()
            ->map(function (Loan $loan): array {
                $member = $loan->member;

                return [
                    'id' => $loan->id,
                    'member_name' => $member?->name ?? '—',
                    'member_initials' => $member ? mb_strtoupper(
                        collect(explode(' ', $member->name))
                            ->filter()
                            ->map(fn (string $w): string => mb_substr($w, 0, 1))
                            ->take(2)
                            ->implode('')
                    ) : '??',
                    'amount' => (float) $loan->amount_requested,
                    'is_emergency' => $loan->is_emergency,
                    'queue_position' => $loan->queue_position ?? 0,
                    'url' => LoanResource::getUrl('view', ['record' => $loan]),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return FundAuditLog::query()
            ->with('member')
            ->latest('occurred_at')
            ->limit(6)
            ->get()
            ->map(function (FundAuditLog $log): array {
                $member = $log->member;
                $eventType = $log->event_type ?? '';
                $domain = $log->domain ?? '';

                $chip = match (true) {
                    str_contains($eventType, 'loan') || str_contains($domain, 'loan') => ['label' => Lang::ui('Loan'), 'class' => 'ff-chip-blue'],
                    str_contains($eventType, 'contribution') || str_contains($domain, 'contribution') => ['label' => Lang::ui('Contribution'), 'class' => 'ff-chip-green'],
                    str_contains($eventType, 'recon') || str_contains($domain, 'recon') => ['label' => Lang::ui('Recon'), 'class' => 'ff-chip-amber'],
                    str_contains($eventType, 'migration') => ['label' => Lang::ui('Migration'), 'class' => 'ff-chip-purple'],
                    str_contains($eventType, 'override') => ['label' => Lang::ui('Override'), 'class' => 'ff-chip-amber'],
                    default => ['label' => Lang::ui('System'), 'class' => 'ff-chip-gray'],
                };

                $payload = $log->payload ?? [];
                $description = $payload['description'] ?? $payload['message'] ?? str_replace('_', ' ', ucfirst($eventType));

                return [
                    'initials' => $member ? mb_strtoupper(
                        collect(explode(' ', $member->name))
                            ->filter()
                            ->map(fn (string $w): string => mb_substr($w, 0, 1))
                            ->take(2)
                            ->implode('')
                    ) : 'SY',
                    'member_name' => $member?->name ?? Lang::ui('System'),
                    'description' => $description,
                    'chip' => $chip,
                    'time' => $log->occurred_at?->diffForHumans() ?? '',
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, int>  $delinquencyCounts
     * @return array<string, mixed>
     */
    private function collectionBreakdown(int $month, int $year, int $activeMembers, array $delinquencyCounts): array
    {
        if ($activeMembers === 0) {
            return [
                'posted_pct' => 0,
                'pending_pct' => 0,
                'failed_pct' => 0,
                'waived_pct' => 0,
                'posted' => 0,
                'pending' => 0,
                'failed' => 0,
                'waived' => 0,
                'tier1' => 0,
                'tier2' => 0,
                'tier3' => 0,
                'total' => 0,
            ];
        }

        $periodDate = Contribution::periodDate($month, $year);

        $statusCounts = Contribution::query()
            ->where('period', $periodDate)
            ->selectRaw('status, COUNT(DISTINCT member_id) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $posted = (int) ($statusCounts['posted'] ?? 0);
        $pending = (int) ($statusCounts['pending'] ?? 0);
        $failed = (int) ($statusCounts['failed'] ?? 0);
        $waived = (int) ($statusCounts['waived'] ?? 0);

        $tier1 = (int) Contribution::query()->where('period', $periodDate)->where('late_fee_tier', 1)->count();
        $tier2 = (int) Contribution::query()->where('period', $periodDate)->where('late_fee_tier', 2)->count();
        $tier3 = (int) Contribution::query()->where('period', $periodDate)->where('late_fee_tier', '>=', 3)->count();

        $total = max(1, $activeMembers);

        return [
            'posted_pct' => min(100, round(($posted / $total) * 100)),
            'pending_pct' => min(100, round(($pending / $total) * 100)),
            'failed_pct' => min(100, round(($failed / $total) * 100)),
            'waived_pct' => min(100, round(($waived / $total) * 100)),
            'posted' => $posted,
            'pending' => $pending,
            'failed' => $failed,
            'waived' => $waived,
            'tier1' => $tier1,
            'tier2' => $tier2,
            'tier3' => $tier3,
            'total' => $activeMembers,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fundTierUtilisation(): array
    {
        $tiers = FundTier::query()
            ->where('is_active', true)
            ->orderBy('tier_number')
            ->get();

        return $tiers->map(function (FundTier $tier): array {
            $allocated = $tier->allocated_amount;
            $exposure = $tier->active_exposure;
            $pct = $allocated > 0 ? min(100, round(($exposure / $allocated) * 100)) : 0;

            $tone = match (true) {
                $pct >= 90 => 'danger',
                $pct >= 70 => 'warning',
                default => 'success',
            };

            $bar = match ($tone) {
                'danger' => '#E24B4A',
                'warning' => '#EF9F27',
                default => '#1D9E75',
            };

            return [
                'label' => $tier->tier_label ?? ($tier->is_emergency ? __('Emergency') : __('Tier :n', ['n' => $tier->tier_number])),
                'pct' => $pct,
                'bar_color' => $bar,
                'tone' => $tone,
                'available_amount' => (float) $tier->available_amount,
                'url' => FundTierResource::getUrl('index'),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workspaceSections(): array
    {
        $sections = [
            [
                'title' => Lang::ui('Members & contributions'),
                'links' => [
                    ['label' => Lang::ui('Members'), 'icon' => 'heroicon-o-users', 'url' => MemberResource::getUrl('index')],
                    ['label' => Lang::ui('Member accounts'), 'icon' => 'heroicon-o-wallet', 'url' => AccountResource::getUrl('index')],
                    ['label' => Lang::ui('Contributions'), 'icon' => 'heroicon-o-calendar-days', 'url' => ContributionResource::getUrl('index')],
                    ['label' => Lang::ui('Open cycle'), 'icon' => 'heroicon-o-arrow-path-rounded-square', 'url' => ContributionResource::listTabUrl('collect')],
                    ['label' => Lang::ui('Monthly statements'), 'icon' => 'heroicon-o-document-text', 'url' => MonthlyStatementResource::getUrl('index')],
                    ['label' => Lang::ui('Applications'), 'icon' => 'heroicon-o-user-plus', 'url' => MembershipApplicationResource::getUrl('index')],
                ],
            ],
            [
                'title' => Lang::ui('Loans & tiers'),
                'links' => [
                    ['label' => Lang::ui('All loans'), 'icon' => 'heroicon-o-banknotes', 'url' => LoanResource::getUrl('index')],
                    ['label' => Lang::ui('Loan queue'), 'icon' => 'heroicon-o-queue-list', 'url' => LoanResource::getUrl('queue')],
                    ['label' => Lang::ui('Overdue installments'), 'icon' => 'heroicon-o-calendar-days', 'url' => LoanResource::listTabUrl('overdue_installments')],
                    ['label' => Lang::ui('Contribution arrears'), 'icon' => 'heroicon-o-banknotes', 'url' => ContributionResource::listTabUrl('arrears')],
                    ['label' => Lang::ui('Delinquent members'), 'icon' => 'heroicon-o-user-minus', 'url' => MemberResource::listTabUrl('delinquent')],
                    ['label' => Lang::ui('Loan tiers'), 'icon' => 'heroicon-o-squares-2x2', 'url' => LoanTierResource::getUrl('index')],
                    ['label' => Lang::ui('Fund tiers'), 'icon' => 'heroicon-o-chart-pie', 'url' => FundTierResource::getUrl('index')],
                ],
            ],
            [
                'title' => Lang::ui('Treasury & banking'),
                'links' => [
                    ['label' => Lang::ui('Master accounts'), 'icon' => 'heroicon-o-building-library', 'url' => MasterAccountResource::getUrl('index')],
                    ['label' => Lang::ui('Bank workspace'), 'icon' => 'heroicon-o-building-office-2', 'url' => BankAccountsResource::getUrl('index')],
                    ['label' => Lang::ui('Deposits'), 'icon' => 'heroicon-o-inbox-arrow-down', 'url' => FundPostingResource::getUrl('index')],
                ],
            ],
            [
                'title' => Lang::ui('System'),
                'links' => [
                    ['label' => Lang::ui('Jobs & commands'), 'icon' => 'heroicon-o-cpu-chip', 'url' => JobsPage::getUrl()],
                    ['label' => Lang::ui('Reconciliation'), 'icon' => 'heroicon-o-shield-exclamation', 'url' => ReconciliationOverviewPage::getUrl()],
                    ['label' => Lang::ui('Fund settings'), 'icon' => 'heroicon-o-cog-6-tooth', 'url' => Settings::getUrl()],
                ],
            ],
        ];

        return array_map(
            fn (array $section): array => [
                ...$section,
                'links' => Lang::formatLabeledRows($section['links']),
            ],
            $sections,
        );
    }
}
