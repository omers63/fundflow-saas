<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Pages\MigrationWorkflowPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\User;
use App\Services\Loans\LoanDelinquencyService;
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
        $now = Carbon::now();
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
        $openReconciliationCount = $this->openReconciliationCount();
        $attentionTotal = $pendingContributions + $pendingDeposits + $pendingApplications + $loanQueueCount
            + ($delinquencyCounts['overdue_installments'] ?? 0)
            + ($delinquencyCounts['contribution_arrears_periods'] ?? 0)
            + $openReconciliationCount;

        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $openPeriodLabel = $this->cycles->periodLabel($openMonth, $openYear);
        $collectionGauge = $this->contributionCollectionGauge($openMonth, $openYear, $activeMembers);

        return [
            'currency' => $currency,
            'greeting' => $this->greeting($user, $now, $attentionTotal),
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
                $delinquencyCounts,
                $bankSnapshot,
                $openReconciliationCount,
            ),
            'contribution_trend' => $this->contributionTrend($now),
            'loan_trend' => $loanPortfolio['trend'] ?? [],
            'loan_pipeline' => $loanPortfolio['pipeline'] ?? [],
            'workspace_sections' => $this->workspaceSections(),
            'sparkline' => $masterSnapshot['sparkline'] ?? [],
            'sparkline_max' => $masterSnapshot['sparkline_max'] ?? 1,
            'open_period_label' => $openPeriodLabel,
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
                'amount' => InsightFormatter::money($masterBalance($item['type'])),
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
                'url' => ContributionCyclePage::getUrl(),
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
                'url' => LoanResource::getUrl('delinquency'),
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
                'url' => ContributionCyclePage::getUrl(),
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
            'url' => ContributionCyclePage::getUrl(),
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

    /**
     * @param  array<string, int>  $delinquencyCounts
     * @return list<array<string, mixed>>
     */
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
        array $delinquencyCounts,
        array $bankSnapshot,
        int $openReconciliationCount,
    ): array {
        $cards = [];

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
                'url' => ReconciliationExceptionResource::getUrl('index'),
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
                'url' => LoanResource::getUrl('delinquency'),
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
        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $posted = (float) Contribution::query()
                ->where('status', 'posted')
                ->whereBetween('posted_at', [$month, $end])
                ->sum('amount');

            $count = Contribution::query()
                ->where('status', 'posted')
                ->whereBetween('posted_at', [$month, $end])
                ->count();

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
                    ['label' => Lang::ui('Contribution cycle'), 'icon' => 'heroicon-o-arrow-path-rounded-square', 'url' => ContributionCyclePage::getUrl()],
                    ['label' => Lang::ui('Migrations'), 'icon' => 'heroicon-o-clock', 'url' => MigrationWorkflowPage::getUrl()],
                    ['label' => Lang::ui('Monthly statements'), 'icon' => 'heroicon-o-document-text', 'url' => MonthlyStatementResource::getUrl('index')],
                    ['label' => Lang::ui('Applications'), 'icon' => 'heroicon-o-user-plus', 'url' => MembershipApplicationResource::getUrl('index')],
                ],
            ],
            [
                'title' => Lang::ui('Loans & tiers'),
                'links' => [
                    ['label' => Lang::ui('All loans'), 'icon' => 'heroicon-o-banknotes', 'url' => LoanResource::getUrl('index')],
                    ['label' => Lang::ui('Loan queue'), 'icon' => 'heroicon-o-queue-list', 'url' => LoanResource::getUrl('queue')],
                    ['label' => Lang::ui('Delinquency'), 'icon' => 'heroicon-o-exclamation-triangle', 'url' => LoanResource::getUrl('delinquency')],
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
                    ['label' => Lang::ui('Reconciliation'), 'icon' => 'heroicon-o-shield-exclamation', 'url' => ReconciliationExceptionResource::getUrl('index')],
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
