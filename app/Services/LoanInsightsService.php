<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightKpi;
use App\Support\LoanEligibilityGate;
use App\Support\Loans\LoanUserFacingStage;
use Carbon\Carbon;
use Filament\Facades\Filament;

final class LoanInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function forContext(string $context, ?Loan $loan = null, ?string $queueTab = null, ?int $memberId = null): array
    {
        return match ($context) {
            'portfolio' => $this->portfolioSnapshot(),
            'queue' => $this->queueSnapshot($queueTab ?? 'needs_decision'),
            'loan_tiers' => $this->loanTiersSnapshot(),
            'fund_tiers' => $this->fundTiersSnapshot(),
            'loan_detail' => $loan ? $this->loanDetailSnapshot($loan) : [],
            'member_portfolio' => $this->memberPortfolioSnapshot($memberId),
            'delinquency' => $this->delinquencySnapshot(),
            'eligibility_reviews' => $this->eligibilityReviewsSnapshot(),
            'emi_collect' => $this->emiCollectSnapshot(),
            'emi_collected' => $this->emiCollectedSnapshot(),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function portfolioSnapshot(): array
    {
        $now = BusinessDay::now();
        $currency = Setting::get('general', 'currency', 'USD');

        $pending = Loan::query()->pending()->count();
        $needsDecision = Loan::query()->needsDecision()->count();
        $readyToDisburse = Loan::query()->readyToDisburse()->count();
        $active = Loan::query()->where('status', 'active')->count();
        $completed = Loan::query()->whereIn('status', ['completed', 'early_settled'])->count();
        $queueTotal = $needsDecision + $readyToDisburse;

        $outstanding = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->sum('amount');
        $next30DaysDue = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereBetween('due_date', [$now->copy()->startOfDay(), $now->copy()->addDays(30)->endOfDay()])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
            ->sum('amount');
        $next30DaysCount = (int) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereBetween('due_date', [$now->copy()->startOfDay(), $now->copy()->addDays(30)->endOfDay()])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
            ->count();

        $activeAmountTotal = (float) Loan::query()
            ->where('status', 'active')
            ->selectRaw('COALESCE(SUM(COALESCE(amount_approved, amount_requested, amount, 0)), 0) as total')
            ->value('total');

        $overdueCount = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->count();

        $newThisMonth = Loan::query()
            ->whereMonth('applied_at', $now->month)
            ->whereYear('applied_at', $now->year)
            ->count();

        $newLastMonth = Loan::query()
            ->whereMonth('applied_at', $now->copy()->subMonth()->month)
            ->whereYear('applied_at', $now->copy()->subMonth()->year)
            ->count();

        $disbursedThisMonth = (float) Loan::query()
            ->whereNotNull('disbursed_at')
            ->whereMonth('disbursed_at', $now->month)
            ->whereYear('disbursed_at', $now->year)
            ->sum('amount_disbursed');

        $approvedThisMonth = Loan::query()
            ->whereNotNull('approved_at')
            ->whereMonth('approved_at', $now->month)
            ->whereYear('approved_at', $now->year)
            ->count();

        $approvedDecisions = Loan::query()->whereIn('status', ['approved', 'active', 'completed', 'early_settled'])->count();
        $rejectedDecisions = Loan::query()->whereIn('status', ['rejected', 'cancelled'])->count();
        $approvalRate = ($approvedDecisions + $rejectedDecisions) > 0
            ? round(($approvedDecisions / ($approvedDecisions + $rejectedDecisions)) * 100, 1)
            : null;
        $readyToDisburseAmount = (float) Loan::query()
            ->readyToDisburse()
            ->selectRaw('COALESCE(SUM(COALESCE(amount_approved, amount_requested, amount, 0)), 0) as total')
            ->value('total');
        $availableFundHeadroom = (float) FundTier::query()
            ->where('is_active', true)
            ->get()
            ->sum(fn (FundTier $tier): float => (float) $tier->available_amount);
        $headroomDelta = round($availableFundHeadroom - $readyToDisburseAmount, 2);

        $emergencyInQueue = Loan::query()->inQueue()->where('is_emergency', true)->count();
        $pendingEligibilityReviews = LoanEligibilityOverrideRequest::isTableReady()
            ? LoanEligibilityOverrideRequest::pending()->count()
            : 0;
        $eligibilityReviewsUrl = LoanEligibilityOverrideRequestResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);

        $oldestPending = Loan::query()
            ->needsDecision()
            ->with('member')
            ->orderBy('applied_at')
            ->limit(5)
            ->get()
            ->map(fn (Loan $loan): array => $this->queueLoanRow($loan, $now))
            ->all();

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => ($queueTotal + $pendingEligibilityReviews) > 0 ? 'amber' : 'success',
                'title' => $pendingEligibilityReviews > 0 && $queueTotal === 0
                    ? trans_choice(
                        ':count eligibility review pending|:count eligibility reviews pending',
                        $pendingEligibilityReviews,
                        ['count' => $pendingEligibilityReviews],
                    )
                    : ($queueTotal > 0
                        ? __('Loan operations need attention')
                        : __('Loan pipeline is clear')),
                'subtitle' => $pendingEligibilityReviews > 0 && $queueTotal === 0
                    ? __('Members asked for a loan eligibility override review.')
                    : ($queueTotal > 0
                        ? trans_choice(
                            ':decision pending · :disburse ready',
                            $queueTotal,
                            [
                                'decision' => $needsDecision,
                                'disburse' => $readyToDisburse,
                            ]
                        )
                        : __('No loans waiting for decision or disbursement.')),
                'cta_label' => $pendingEligibilityReviews > 0 && $queueTotal === 0
                    ? __('Review requests')
                    : ($queueTotal > 0 ? __('Open queue') : null),
                'cta_url' => $pendingEligibilityReviews > 0 && $queueTotal === 0
                    ? $eligibilityReviewsUrl
                    : ($queueTotal > 0 ? LoanResource::queueUrl() : null),
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'pending', 'label' => __('Pending'), 'value' => (string) $pending, 'sub' => __('Applications'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $pending > 0],
                ['key' => 'active', 'label' => __('Active'), 'value' => (string) $active, 'sub' => __('Repaying'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'emerald', 'active' => true],
                ['key' => 'outstanding', 'label' => __('Outstanding'), ...InsightKpi::moneyValue($outstanding, $currency), 'sub' => __('Portfolio'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => $outstanding > 0],
                ['key' => 'overdue', 'label' => __('Overdue'), 'value' => (string) $overdueCount, 'sub' => __('Installments'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $overdueCount > 0, 'value_class' => $overdueCount > 0 ? 'text-rose-600 dark:text-rose-400' : null],
                ['key' => 'new', 'label' => __('New/mo'), 'value' => (string) $newThisMonth, 'sub' => $this->monthOverMonthChange($newThisMonth, $newLastMonth) !== null ? __(':percent%', ['percent' => $this->monthOverMonthChange($newThisMonth, $newLastMonth)]) : BusinessDay::now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'sky', 'active' => true, 'mom' => $this->monthOverMonthChange($newThisMonth, $newLastMonth)],
                ['key' => 'disbursed', 'label' => __('Disbursed'), ...InsightKpi::moneyValue($disbursedThisMonth, $currency), 'sub' => __('This month'), 'icon' => 'heroicon-o-arrow-trending-up', 'accent' => 'teal', 'active' => true],
            ], [
                'pending' => LoanResource::listUrl('portfolio', ['status' => ['value' => 'pending']]),
                'active' => LoanResource::listUrl('portfolio', ['status' => ['value' => 'active']]),
                'outstanding' => LoanResource::listUrl(),
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
                'new' => LoanResource::listUrl(),
                'disbursed' => LoanResource::listUrl(),
            ]),
            'sparkline' => $this->weeklyApplicationSparkline(),
            'pipeline' => [
                'needs_decision' => $needsDecision,
                'ready_to_disburse' => $readyToDisburse,
                'active' => $active,
                'completed' => $completed,
                'active_amount_total' => $activeAmountTotal,
                'outstanding_total' => $outstanding,
                'overdue_installments' => $overdueCount,
                'approved_month' => $approvedThisMonth,
                'queue_url' => LoanResource::queueUrl(),
                'queue_needs_decision_url' => LoanResource::queueUrl('needs_decision'),
                'queue_ready_to_disburse_url' => LoanResource::queueUrl('ready_to_disburse'),
                'loans_url' => LoanResource::listUrl(),
                'loans_active_url' => LoanResource::listUrl('portfolio', ['status' => ['value' => 'active']]),
                'loans_completed_url' => LoanResource::listUrl('portfolio', ['status' => ['value' => 'completed']]),
                'pending_eligibility_reviews' => $pendingEligibilityReviews,
                'eligibility_reviews_url' => $eligibilityReviewsUrl,
            ],
            'forecast' => [
                'next_30_days_count' => $next30DaysCount,
                'next_30_days_amount' => $next30DaysDue,
                'ready_to_disburse_amount' => $readyToDisburseAmount,
                'available_fund_headroom' => $availableFundHeadroom,
                'headroom_delta' => $headroomDelta,
                'tone' => $headroomDelta < 0 ? 'danger' : ($readyToDisburseAmount > 0 || $next30DaysCount > 0 ? 'warning' : 'success'),
            ],
            'status_breakdown' => $this->statusBreakdown(),
            'trend' => $this->sixMonthLoanTrend(),
            'oldest_pending' => $oldestPending,
            'fund_utilization' => $this->fundTierUtilization($currency),
            'emergency_in_queue' => $emergencyInQueue,
            'approval_rate' => $approvalRate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function delinquencySnapshot(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $delinquency = app(LoanDelinquencyService::class);

        $overdueInstallments = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->count();

        $overdueAmount = (float) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->sum('amount');

        $counts = $delinquency->digestCounts();
        $delinquentMembers = $counts['delinquent_members'];
        $contributionArrears = $counts['contribution_arrears_periods'];
        $contributionArrearsMembers = $counts['contribution_arrears_members'];
        $guarantorTransferred = (int) Loan::query()
            ->where('status', 'active')
            ->whereNotNull('guarantor_liability_transferred_at')
            ->count();
        $guarantorAtRisk = $delinquency->loansAtGuarantorRiskCount();

        $totalIssues = $overdueInstallments + $contributionArrears;

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => $totalIssues > 0 ? 'rose' : 'success',
                'title' => $totalIssues > 0
                    ? __('Delinquency requires action')
                    : __('Collections are current'),
                'subtitle' => $totalIssues > 0
                    ? __(':overdue overdue installment(s) · :arrears contribution arrears · :delinquent delinquent member(s)', [
                        'overdue' => $overdueInstallments,
                        'arrears' => $contributionArrears,
                        'delinquent' => $delinquentMembers,
                    ])
                    : __('No overdue installments or contribution arrears in the current lookback.'),
                'cta_label' => $totalIssues > 0 ? __('Review overdue') : null,
                'cta_url' => $totalIssues > 0 ? LoanResource::listTabUrl('overdue_installments') : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'overdue', 'label' => __('Overdue'), 'value' => (string) $overdueInstallments, 'sub' => __('Installments'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'rose', 'active' => $overdueInstallments > 0, 'value_class' => $overdueInstallments > 0 ? 'text-rose-600 dark:text-rose-400' : null],
                ['key' => 'at_risk', 'label' => __('At risk'), ...InsightKpi::moneyValue($overdueAmount, $currency), 'sub' => $this->formatMoneyCompact($overdueAmount, $currency), 'icon' => 'heroicon-o-scale', 'accent' => 'amber', 'active' => $overdueAmount > 0],
                ['key' => 'arrears', 'label' => __('Arrears'), 'value' => (string) $contributionArrears, 'sub' => trans_choice(':count member|:count members', $contributionArrearsMembers, ['count' => $contributionArrearsMembers]), 'icon' => 'heroicon-o-banknotes', 'accent' => 'amber', 'active' => $contributionArrears > 0],
                ['key' => 'delinquent', 'label' => __('Delinquent'), 'value' => (string) $delinquentMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-minus', 'accent' => 'violet', 'active' => $delinquentMembers > 0],
                ['key' => 'guarantor', 'label' => __('Guarantor'), 'value' => (string) $guarantorTransferred, 'sub' => __('Liability transferred'), 'icon' => 'heroicon-o-shield-exclamation', 'accent' => 'sky', 'active' => $guarantorTransferred > 0],
                ['key' => 'exposure', 'label' => __('Exposure'), 'value' => (string) $guarantorAtRisk, 'sub' => __('Past grace'), 'icon' => 'heroicon-o-exclamation-circle', 'accent' => 'rose', 'active' => $guarantorAtRisk > 0],
            ], [
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
                'at_risk' => LoanResource::listTabUrl('overdue_installments'),
                'arrears' => ContributionResource::listTabUrl('arrears'),
                'delinquent' => MemberResource::listTabUrl('delinquent'),
                'guarantor' => LoanResource::listTabUrl('guarantor_exposure'),
                'exposure' => LoanResource::listTabUrl('guarantor_exposure'),
            ]),
            'pipeline' => [
                'overdue_installments' => $overdueInstallments,
                'contribution_arrears' => $contributionArrears,
                'guarantor_at_risk' => $guarantorAtRisk,
                'guarantor_transferred' => $guarantorTransferred,
                'delinquent_members' => $delinquentMembers,
                'delinquency_url' => LoanResource::listTabUrl('overdue_installments'),
                'delinquency_installments_url' => LoanResource::listTabUrl('overdue_installments'),
                'delinquency_contributions_url' => ContributionResource::listTabUrl('arrears'),
                'delinquency_guarantor_url' => LoanResource::listTabUrl('guarantor_exposure'),
                'delinquency_members_url' => MemberResource::listTabUrl('delinquent'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function eligibilityReviewsSnapshot(): array
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return [];
        }

        $now = BusinessDay::now();
        $gateLabels = LoanEligibilityGate::labels();

        $pending = LoanEligibilityOverrideRequest::query()->pending()->count();
        $approved = LoanEligibilityOverrideRequest::query()->where('status', 'approved')->count();
        $rejected = LoanEligibilityOverrideRequest::query()->where('status', 'rejected')->count();
        $standingOverrides = LoanEligibilityOverride::query()->count();
        $submittedThisMonth = LoanEligibilityOverrideRequest::query()
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        $oldestPending = LoanEligibilityOverrideRequest::query()
            ->pending()
            ->orderBy('created_at')
            ->first();

        $oldestPendingDays = $oldestPending?->created_at !== null
            ? (int) Carbon::parse($oldestPending->created_at)->diffInDays($now)
            : 0;

        $gateCounts = [];

        foreach (LoanEligibilityOverrideRequest::query()->pending()->get(['failed_gates']) as $request) {
            foreach ($request->gateKeys() as $gate) {
                $gateCounts[$gate] = ($gateCounts[$gate] ?? 0) + 1;
            }
        }

        arsort($gateCounts);

        $topBlockedRules = collect($gateCounts)
            ->take(4)
            ->map(fn (int $count, string $gate): array => [
                'label' => $gateLabels[$gate] ?? $gate,
                'count' => $count,
            ])
            ->values()
            ->all();

        $maxGateCount = max(1, collect($topBlockedRules)->max('count') ?? 0);

        $preview = LoanEligibilityOverrideRequest::query()
            ->pending()
            ->with('member')
            ->orderBy('created_at')
            ->limit(5)
            ->get()
            ->map(fn (LoanEligibilityOverrideRequest $request): array => $this->eligibilityReviewRow($request, $now))
            ->all();

        $pendingUrl = LoanEligibilityOverrideRequestResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);

        return [
            'hero' => [
                'tone' => $pending > 0 ? 'amber' : 'success',
                'title' => $pending > 0
                    ? trans_choice(':count eligibility review pending|:count eligibility reviews pending', $pending, ['count' => $pending])
                    : __('No pending eligibility reviews'),
                'subtitle' => $pending > 0
                    ? __('Oldest request waiting :days day(s). Approved reviews create standing overrides on the member record.', [
                        'days' => $oldestPendingDays,
                    ])
                    : __('Members can request a review when loan eligibility rules block an application.'),
                'cta_label' => $pending > 0 ? __('Review pending') : null,
                'cta_url' => $pending > 0 ? $pendingUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'pending', 'label' => __('Pending'), 'value' => (string) $pending, 'sub' => __('Awaiting review'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $pending > 0],
                ['key' => 'approved', 'label' => __('Approved'), 'value' => (string) $approved, 'sub' => __('All time'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => $approved > 0],
                ['key' => 'rejected', 'label' => __('Rejected'), 'value' => (string) $rejected, 'sub' => __('All time'), 'icon' => 'heroicon-o-x-circle', 'accent' => 'rose', 'active' => $rejected > 0],
                ['key' => 'overrides', 'label' => __('Overrides'), 'value' => (string) $standingOverrides, 'sub' => __('Standing rules'), 'icon' => 'heroicon-o-shield-check', 'accent' => 'sky', 'active' => $standingOverrides > 0],
                ['key' => 'submitted', 'label' => __('Submitted'), 'value' => (string) $submittedThisMonth, 'sub' => $now->format('M Y'), 'icon' => 'heroicon-o-inbox-arrow-down', 'accent' => 'violet', 'active' => $submittedThisMonth > 0],
                ['key' => 'oldest', 'label' => __('Oldest wait'), 'value' => (string) $oldestPendingDays, 'sub' => __('Days'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'teal', 'active' => $oldestPendingDays > 0],
            ], [
                'pending' => $pendingUrl,
                'approved' => LoanEligibilityOverrideRequestResource::listUrl(['status' => ['value' => 'approved']]),
                'rejected' => LoanEligibilityOverrideRequestResource::listUrl(['status' => ['value' => 'rejected']]),
                'overrides' => LoanEligibilityOverrideResource::getUrl('index'),
                'submitted' => LoanResource::listUrl('eligibility_reviews'),
                'oldest' => $pendingUrl,
            ]),
            'pipeline' => [
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
                'standing_overrides' => $standingOverrides,
                'pending_url' => $pendingUrl,
                'approved_url' => LoanEligibilityOverrideRequestResource::listUrl(['status' => ['value' => 'approved']]),
                'rejected_url' => LoanEligibilityOverrideRequestResource::listUrl(['status' => ['value' => 'rejected']]),
                'overrides_url' => LoanEligibilityOverrideResource::getUrl('index'),
            ],
            'preview' => $preview,
            'top_blocked_rules' => $topBlockedRules,
            'max_gate_count' => $maxGateCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emiCollectSnapshot(): array
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $catalog->periodLabel($month, $year);
        $metrics = $this->aggregateEmiCollectMetrics($catalog, $month, $year);

        $pendingMembers = $metrics['pending_members'];
        $collectedCount = $metrics['collected_count'];
        $collectedAmount = $metrics['collected_amount'];
        $totalPendingEmis = $metrics['total_pending_emis'];
        $readyWithCash = $metrics['ready_with_cash'];
        $requiredCashTotal = $metrics['required_cash_total'];
        $collectionRate = $metrics['collection_rate'];
        $cycleForecast = app(CycleForecastService::class)->project(
            $month,
            $year,
            $collectedCount,
            $collectedCount + $totalPendingEmis,
            $collectedAmount,
            $collectedAmount + $requiredCashTotal,
        );

        $overdueInstallments = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
            ->count();

        $collectUrl = LoanResource::listTabUrl('emi_collect');

        $preview = $catalog->membersWithCollectableEmisQuery($month, $year)
            ->limit(5)
            ->get()
            ->map(fn (Member $member): array => $this->emiCollectPreviewRow($catalog, $member, $month, $year))
            ->all();

        return [
            'currency' => $currency,
            'open_period' => [
                'label' => $periodLabel,
                'collection_rate' => $collectionRate,
                'missing_members' => $pendingMembers,
            ],
            'hero' => [
                'tone' => $pendingMembers > 0 ? 'amber' : 'success',
                'title' => $pendingMembers > 0
                    ? __('EMI collection in progress')
                    : __('Open period EMIs collected'),
                'subtitle' => $pendingMembers > 0
                    ? trans_choice(
                        ':count member with EMIs to collect for :period|:count members with EMIs to collect for :period',
                        $pendingMembers,
                        ['count' => $pendingMembers, 'period' => $periodLabel],
                    )
                    : __('All collectable installments for :period are paid from member cash.', ['period' => $periodLabel]),
                'cta_label' => $pendingMembers > 0 ? __('EMI collection') : null,
                'cta_url' => $pendingMembers > 0 ? $collectUrl : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'missing', 'label' => __('To collect'), 'value' => (string) $pendingMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $pendingMembers > 0],
                ['key' => 'collected', 'label' => __('Collected'), 'value' => (string) $collectedCount, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => $collectedCount > 0],
                ['key' => 'pending_emis', 'label' => __('Pending EMIs'), 'value' => (string) $totalPendingEmis, 'sub' => __('Installments'), 'icon' => 'heroicon-o-clock', 'accent' => 'sky', 'active' => $totalPendingEmis > 0],
                ['key' => 'rate', 'label' => __('Collection'), 'value' => $collectionRate.'%', 'sub' => __('Open period'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                ['key' => 'ready_cash', 'label' => __('Ready'), 'value' => (string) $readyWithCash, 'sub' => __('Sufficient cash'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'teal', 'active' => $readyWithCash > 0],
                ['key' => 'overdue', 'label' => __('Overdue'), 'value' => (string) $overdueInstallments, 'sub' => __('Installments'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $overdueInstallments > 0, 'value_class' => $overdueInstallments > 0 ? 'text-rose-600 dark:text-rose-400' : null],
            ], [
                'missing' => $collectUrl,
                'collected' => LoanResource::listTabUrl('emi_collected'),
                'pending_emis' => $collectUrl,
                'rate' => $collectUrl,
                'ready_cash' => $collectUrl,
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
            ]),
            'pipeline' => [
                'missing_open_period' => $pendingMembers,
                'collected_open_period' => $collectedCount,
                'ready_with_cash' => $readyWithCash,
                'required_cash' => $requiredCashTotal,
                'overdue_installments' => $overdueInstallments,
                'collect_url' => $collectUrl,
                'collected_url' => LoanResource::listTabUrl('emi_collected'),
                'overdue_url' => LoanResource::listTabUrl('overdue_installments'),
            ],
            'forecast' => $cycleForecast + [
                'ready_cash_total' => $metrics['ready_cash_total'],
                'uncovered_amount' => $metrics['uncovered_amount'],
            ],
            'preview' => $preview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emiCollectedSnapshot(): array
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');
        $periodLabel = $catalog->periodLabel($month, $year);
        $metrics = $this->aggregateEmiCollectMetrics($catalog, $month, $year);

        $collectedCount = $metrics['collected_count'];
        $collectedAmount = (float) $catalog->collectedInstallmentsQuery($month, $year)->sum('amount');
        $pendingMembers = $metrics['pending_members'];
        $collectedAmount = $metrics['collected_amount'];
        $cycleForecast = app(CycleForecastService::class)->project(
            $month,
            $year,
            $collectedCount,
            $collectedCount + $metrics['total_pending_emis'],
            $collectedAmount,
            $collectedAmount + $metrics['required_cash_total'],
        );

        $collectedUrl = LoanResource::listTabUrl('emi_collected');

        return [
            'currency' => $currency,
            'open_period' => ['label' => $periodLabel],
            'hero' => [
                'tone' => 'success',
                'title' => __('EMIs collected for :period', ['period' => $periodLabel]),
                'subtitle' => trans_choice(
                    ':count paid installment in the open period|:count paid installments in the open period',
                    $collectedCount,
                    ['count' => $collectedCount],
                ).($pendingMembers > 0
                    ? ' · '.trans_choice(':count member still on EMI collection|:count members still on EMI collection', $pendingMembers, ['count' => $pendingMembers])
                    : ''),
                'cta_label' => $pendingMembers > 0 ? __('EMI collection') : null,
                'cta_url' => $pendingMembers > 0 ? LoanResource::listTabUrl('emi_collect') : null,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'collected', 'label' => __('Collected'), 'value' => (string) $collectedCount, 'sub' => $periodLabel, 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => $collectedCount > 0],
                ['key' => 'amount', 'label' => __('Amount'), ...InsightKpi::moneyValue($collectedAmount, $currency), 'sub' => $periodLabel, 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'teal', 'active' => $collectedAmount > 0],
                ['key' => 'remaining', 'label' => __('Remaining'), 'value' => (string) $pendingMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-group', 'accent' => 'amber', 'active' => $pendingMembers > 0],
                ['key' => 'pending_emis', 'label' => __('Pending EMIs'), 'value' => (string) $metrics['total_pending_emis'], 'sub' => __('Installments'), 'icon' => 'heroicon-o-clock', 'accent' => 'sky', 'active' => $metrics['total_pending_emis'] > 0],
                ['key' => 'collect', 'label' => __('To collect'), 'value' => (string) $pendingMembers, 'sub' => __('Open period'), 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => 'violet', 'active' => $pendingMembers > 0],
                [
                    'key' => 'overdue',
                    'label' => __('Overdue'),
                    'value' => (string) LoanInstallment::query()
                        ->where('status', 'overdue')
                        ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
                        ->count(),
                    'sub' => __('Installments'),
                    'icon' => 'heroicon-o-exclamation-triangle',
                    'accent' => 'rose',
                    'active' => true,
                ],
            ], [
                'collected' => $collectedUrl,
                'amount' => $collectedUrl,
                'remaining' => LoanResource::listTabUrl('emi_collect'),
                'pending_emis' => LoanResource::listTabUrl('emi_collect'),
                'collect' => LoanResource::listTabUrl('emi_collect'),
                'overdue' => LoanResource::listTabUrl('overdue_installments'),
            ]),
            'pipeline' => [
                'collected_open_period' => $collectedCount,
                'missing_open_period' => $pendingMembers,
                'collected_url' => $collectedUrl,
                'collect_url' => LoanResource::listTabUrl('emi_collect'),
            ],
            'forecast' => $cycleForecast + [
                'ready_cash_total' => $metrics['ready_cash_total'],
                'uncovered_amount' => $metrics['uncovered_amount'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSnapshot(string $activeTab): array
    {
        $now = BusinessDay::now();
        $currency = Setting::get('general', 'currency', 'USD');

        $needsDecision = Loan::query()->needsDecision()->count();
        $readyToDisburse = Loan::query()->readyToDisburse()->count();
        $total = $needsDecision + $readyToDisburse;

        $tabQuery = match ($activeTab) {
            'ready_to_disburse' => Loan::query()->readyToDisburse(),
            default => Loan::query()->needsDecision(),
        };

        $tabCount = (clone $tabQuery)->count();
        $tabExposure = (float) (clone $tabQuery)->sum('amount_requested');

        $preview = (clone $tabQuery)
            ->with(['member', 'fundTier'])
            ->orderBy('queue_position')
            ->orderBy('applied_at')
            ->limit(5)
            ->get()
            ->map(fn (Loan $loan): array => $this->queueLoanRow($loan, $now))
            ->all();

        $emergency = Loan::query()->inQueue()->where('is_emergency', true)->count();

        return [
            'currency' => $currency,
            'active_tab' => $activeTab,
            'hero' => [
                'tone' => $total > 0 ? 'amber' : 'success',
                'title' => match ($activeTab) {
                    'ready_to_disburse' => __('Loans ready to disburse'),
                    default => __('Applications awaiting decision'),
                },
                'subtitle' => trans_choice(
                    ':count in this tab · :total total in queue',
                    $tabCount,
                    ['count' => $tabCount, 'total' => $total]
                ),
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'decision', 'label' => __('Decision'), 'value' => (string) $needsDecision, 'sub' => __('Pending'), 'icon' => 'heroicon-o-clipboard-document-check', 'accent' => 'amber', 'active' => $needsDecision > 0],
                ['key' => 'disburse', 'label' => __('Disburse'), 'value' => (string) $readyToDisburse, 'sub' => __('Approved'), 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'sky', 'active' => $readyToDisburse > 0],
                ['key' => 'tab_total', 'label' => __('Tab total'), ...InsightKpi::moneyValue($tabExposure, $currency), 'sub' => __('Exposure'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => true],
                ['key' => 'emergency', 'label' => __('Emergency'), 'value' => (string) $emergency, 'sub' => __('In queue'), 'icon' => 'heroicon-o-bolt', 'accent' => 'rose', 'active' => $emergency > 0],
                ['key' => 'queue', 'label' => __('Queue'), 'value' => (string) $total, 'sub' => __('All stages'), 'icon' => 'heroicon-o-queue-list', 'accent' => 'teal', 'active' => true],
            ], [
                'decision' => LoanResource::queueUrl('needs_decision'),
                'disburse' => LoanResource::queueUrl('ready_to_disburse'),
                'tab_total' => LoanResource::queueUrl($activeTab),
                'emergency' => LoanResource::queueUrl(),
                'queue' => LoanResource::queueUrl(),
            ]),
            'pipeline' => [
                'needs_decision' => $needsDecision,
                'ready_to_disburse' => $readyToDisburse,
                'queue_url' => LoanResource::queueUrl(),
                'queue_needs_decision_url' => LoanResource::queueUrl('needs_decision'),
                'queue_ready_to_disburse_url' => LoanResource::queueUrl('ready_to_disburse'),
            ],
            'preview' => $preview,
            'tab_labels' => [
                'needs_decision' => __('Needs decision'),
                'ready_to_disburse' => __('Ready to disburse'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loanTiersSnapshot(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');

        $activeTiers = LoanTier::query()->where('is_active', true)->orderBy('tier_number')->get();
        $inactiveCount = LoanTier::query()->where('is_active', false)->count();
        $loansByTier = Loan::query()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->selectRaw('loan_tier_id, COUNT(*) as total')
            ->groupBy('loan_tier_id')
            ->pluck('total', 'loan_tier_id');

        $breakdown = $activeTiers->map(fn (LoanTier $tier): array => [
            'label' => $tier->label,
            'range' => $this->formatMoneyCompact((float) $tier->min_amount, $currency).' – '.$this->formatMoneyCompact((float) $tier->max_amount, $currency),
            'count' => (int) ($loansByTier[$tier->id] ?? 0),
            'min_installment' => $this->formatMoneyCompact((float) $tier->min_monthly_installment, $currency),
        ])->values()->all();

        $maxCount = max(1, collect($breakdown)->max('count') ?? 0);

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => 'success',
                'title' => __('Loan tier configuration'),
                'subtitle' => trans_choice(
                    ':active active tiers · :inactive inactive',
                    $activeTiers->count(),
                    ['active' => $activeTiers->count(), 'inactive' => $inactiveCount]
                ),
                'cta_label' => __('Fund tiers'),
                'cta_url' => FundTierResource::getUrl('index'),
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'active', 'label' => __('Active'), 'value' => (string) $activeTiers->count(), 'sub' => __('Tiers'), 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'emerald', 'active' => true],
                ['key' => 'inactive', 'label' => __('Inactive'), 'value' => (string) $inactiveCount, 'sub' => __('Tiers'), 'icon' => 'heroicon-o-pause-circle', 'accent' => 'violet', 'active' => $inactiveCount > 0],
                ['key' => 'in_flight', 'label' => __('In flight'), 'value' => (string) array_sum($loansByTier->all()), 'sub' => __('Loans'), 'icon' => 'heroicon-o-document-text', 'accent' => 'sky', 'active' => true],
                ['key' => 'min_band', 'label' => __('Min band'), ...($activeTiers->isNotEmpty() ? InsightKpi::moneyValue((float) $activeTiers->min('min_amount'), $currency) : ['value' => '—']), 'sub' => __('Lowest'), 'icon' => 'heroicon-o-arrow-down', 'accent' => 'teal', 'active' => true],
                ['key' => 'max_band', 'label' => __('Max band'), ...($activeTiers->isNotEmpty() ? InsightKpi::moneyValue((float) $activeTiers->max('max_amount'), $currency) : ['value' => '—']), 'sub' => __('Highest'), 'icon' => 'heroicon-o-arrow-up', 'accent' => 'violet', 'active' => true],
                ['key' => 'fund_pools', 'label' => __('Fund pools'), 'value' => (string) FundTier::query()->where('is_active', true)->count(), 'sub' => __('Linked'), 'icon' => 'heroicon-o-circle-stack', 'accent' => 'indigo', 'active' => true],
            ], [
                'active' => LoanTierResource::getUrl('index'),
                'inactive' => LoanTierResource::getUrl('index'),
                'in_flight' => LoanResource::getUrl('index'),
                'min_band' => LoanTierResource::getUrl('index'),
                'max_band' => LoanTierResource::getUrl('index'),
                'fund_pools' => FundTierResource::getUrl('index'),
            ]),
            'breakdown' => $breakdown,
            'max_count' => $maxCount,
            'tiers_url' => LoanTierResource::getUrl('index'),
            'fund_tiers_url' => FundTierResource::getUrl('index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fundTiersSnapshot(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $masterBalance = (float) (Account::masterFund()?->balance ?? 0);

        $tiers = FundTier::query()
            ->where('is_active', true)
            ->with('loanTier')
            ->orderBy('tier_number')
            ->get();

        $totalAllocated = $tiers->sum(fn (FundTier $tier): float => $tier->allocated_amount);
        $totalExposure = $tiers->sum(fn (FundTier $tier): float => $tier->active_exposure);
        $totalAvailable = max(0, $totalAllocated - $totalExposure);
        $utilization = $totalAllocated > 0 ? (int) round(($totalExposure / $totalAllocated) * 100) : 0;

        $breakdown = $tiers->map(function (FundTier $tier): array {
            $allocated = $tier->allocated_amount;
            $exposure = $tier->active_exposure;
            $available = $tier->available_amount;
            $used = $allocated > 0 ? (int) round(($exposure / $allocated) * 100) : 0;

            return [
                'label' => $tier->label,
                'loan_tier' => $tier->loanTier?->label,
                'percentage' => (float) $tier->percentage,
                'allocated' => $allocated,
                'exposure' => $exposure,
                'available' => $available,
                'used_percent' => min(100, $used),
                'active_loans' => $tier->active_loans_count,
                'is_emergency' => $tier->isEmergency(),
            ];
        })->values()->all();

        $maxUsed = max(1, collect($breakdown)->max('used_percent') ?? 0);

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => $utilization >= 85 ? 'danger' : ($utilization >= 60 ? 'amber' : 'success'),
                'title' => __('Fund pool utilization'),
                'subtitle' => __(':used% of :allocated allocated across active fund tiers', [
                    'used' => $utilization,
                    'allocated' => $this->formatMoneyCompact($totalAllocated, $currency),
                ]),
                'cta_label' => __('Loan tiers'),
                'cta_url' => LoanTierResource::getUrl('index'),
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'master_fund', 'label' => __('Master fund'), ...InsightKpi::moneyValue($masterBalance, $currency), 'sub' => __('Balance'), 'icon' => 'heroicon-o-building-library', 'accent' => 'indigo', 'active' => true],
                ['key' => 'allocated', 'label' => __('Allocated'), ...InsightKpi::moneyValue($totalAllocated, $currency), 'sub' => __('Pools'), 'icon' => 'heroicon-o-circle-stack', 'accent' => 'sky', 'active' => true],
                ['key' => 'deployed', 'label' => __('Deployed'), ...InsightKpi::moneyValue($totalExposure, $currency), 'sub' => __('Exposure'), 'icon' => 'heroicon-o-arrow-trending-up', 'accent' => 'amber', 'active' => $totalExposure > 0],
                ['key' => 'available', 'label' => __('Available'), ...InsightKpi::moneyValue($totalAvailable, $currency), 'sub' => __('Headroom'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['key' => 'utilization', 'label' => __('Utilization'), 'value' => $utilization.'%', 'sub' => __('Portfolio'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                ['key' => 'active_tiers', 'label' => __('Active tiers'), 'value' => (string) $tiers->count(), 'sub' => __('Pools'), 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'teal', 'active' => true],
            ], [
                'master_fund' => MasterAccountResource::getUrl('index', ['tab' => 'fund']),
                'allocated' => FundTierResource::getUrl('index'),
                'deployed' => LoanResource::getUrl('index'),
                'available' => FundTierResource::getUrl('index'),
                'utilization' => FundTierResource::getUrl('index'),
                'active_tiers' => FundTierResource::getUrl('index'),
            ]),
            'breakdown' => $breakdown,
            'max_used' => $maxUsed,
            'utilization' => $utilization,
            'fund_tiers_url' => FundTierResource::getUrl('index'),
            'queue_url' => LoanResource::queueUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loanDetailSnapshot(Loan $loan): array
    {
        $loan->loadMissing(['member', 'loanTier', 'fundTier', 'guarantor', 'installments', 'disbursements', 'repayments']);

        $currency = Setting::get('general', 'currency', 'USD');
        $outstanding = $loan->getOutstandingBalance();
        $outstandingBreakdown = $loan->getOutstandingBreakdown();
        $approved = (float) ($loan->amount_approved ?? 0);
        $disbursed = (float) $loan->amount_disbursed;
        $disbursePercent = $approved > 0 ? min(100, (int) round(($disbursed / $approved) * 100)) : 0;

        $installmentsTotal = $loan->installments->count();
        $installmentsPaid = $loan->installments->where('status', 'paid')->count();
        $installmentsOverdue = $loan->installments->where('status', 'overdue')->count();
        $repayPercent = $installmentsTotal > 0 ? (int) round(($installmentsPaid / $installmentsTotal) * 100) : 0;

        $nextInstallment = $loan->installments
            ->whereIn('status', ['pending', 'overdue'])
            ->sortBy('due_date')
            ->first();

        $lateFees = (float) $loan->installments->sum('late_fee_amount');

        $memberPanel = $this->insightsOnMemberPanel();
        $loanViewUrl = $memberPanel
            ? $this->memberLoanViewUrl($loan)
            : LoanResource::getUrl('view', ['record' => $loan]);

        $queueUrl = $loan->status === 'pending'
            ? ($memberPanel ? $this->memberLoansIndexUrl('pending') : LoanResource::queueUrl())
            : null;

        $remainingToDisburse = max(0, $approved - $disbursed);

        return [
            'currency' => $currency,
            'steps' => LoanUserFacingStage::stepperFor($loan),
            'member_name' => $loan->member?->name,
            'status' => $loan->status,
            'status_label' => Loan::statusOptions()[$loan->status] ?? $loan->status,
            'is_emergency' => $loan->is_emergency,
            'snapshot' => [
                'requested' => (float) $loan->amount_requested,
                'approved' => $loan->amount_approved !== null ? (float) $loan->amount_approved : null,
                'disbursed' => $disbursed,
                'outstanding' => $outstanding,
                'outstanding_breakdown' => $outstandingBreakdown,
                'remaining_to_disburse' => $remainingToDisburse,
                'disbursed_formatted' => $this->formatMoneyCompact($disbursed, $currency),
                'remaining_formatted' => $this->formatMoneyCompact($remainingToDisburse, $currency),
                'disburse_percent' => $disbursePercent,
                'repay_percent' => $repayPercent,
                'installments_paid' => $installmentsPaid,
                'installments_total' => $installmentsTotal,
                'installments_overdue' => $installmentsOverdue,
                'queue_position' => $loan->queue_position,
                'fund_tier' => $loan->fundTier?->label,
                'tranche_count' => $loan->disbursements->count(),
                'legacy_repayment_total' => (float) $loan->repayments->sum('amount'),
                'queue_url' => $queueUrl,
                'queue_label' => $memberPanel ? __('My loans') : __('Open queue'),
            ],
            'next_due' => $nextInstallment ? [
                'amount' => (float) $nextInstallment->amount,
                'date' => $nextInstallment->due_date?->format('d M Y'),
                'status' => $nextInstallment->status,
                'is_overdue' => $nextInstallment->status === 'overdue',
            ] : null,
            'guarantor' => $loan->guarantor ? [
                'name' => $loan->guarantor->name,
                'released' => $loan->isGuarantorReleased(),
                'liability_transferred' => $loan->guarantor_liability_transferred_at !== null,
            ] : null,
            'late_fees' => $lateFees,
            'view_url' => $loanViewUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function memberPortfolioSnapshot(?int $memberId): array
    {
        if ($memberId === null) {
            return [];
        }

        $currency = Setting::get('general', 'currency', 'USD');
        $base = Loan::query()->where('member_id', $memberId);

        $pending = (clone $base)->where('status', 'pending')->count();
        $active = (clone $base)->where('status', 'active')->count();
        $completed = (clone $base)->whereIn('status', ['completed', 'early_settled'])->count();

        $outstanding = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($q) => $q->where('member_id', $memberId)->where('status', 'active'))
            ->sum('amount');

        $member = Member::query()->find($memberId);

        if ($member === null) {
            return [];
        }

        $eligibility = app(LoanService::class)->checkEligibility($member);
        $overrideRequests = app(LoanEligibilityOverrideRequestService::class);
        $canRequestOverride = $overrideRequests->canSubmit($member);
        $hasPendingOverrideRequest = $overrideRequests->pendingRequestFor($member) !== null;

        $activeLoan = (clone $base)->where('status', 'active')->latest('applied_at')->first();
        $cashBalance = $member->getCashBalance();
        $nextInstallment = $activeLoan?->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->first();
        $nextEmiAmount = $nextInstallment !== null
            ? round((float) $nextInstallment->amount + (float) ($nextInstallment->late_fee_amount ?? 0), 2)
            : 0.0;
        $cashGap = max(0.0, $nextEmiAmount - $cashBalance);
        $next30DaysAmount = $activeLoan !== null
            ? (float) LoanInstallment::query()
                ->where('loan_id', $activeLoan->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->whereBetween('due_date', [BusinessDay::now()->copy()->startOfDay(), BusinessDay::now()->copy()->addDays(30)->endOfDay()])
                ->sum('amount')
            : 0.0;
        $next30DaysCount = $activeLoan !== null
            ? (int) LoanInstallment::query()
                ->where('loan_id', $activeLoan->id)
                ->whereIn('status', ['pending', 'overdue'])
                ->whereBetween('due_date', [BusinessDay::now()->copy()->startOfDay(), BusinessDay::now()->copy()->addDays(30)->endOfDay()])
                ->count()
            : 0;

        $heroSubtitle = $eligibility['eligible']
            ? __('You may apply for a new loan when eligible.')
            : ($eligibility['reasons'][0] ?? __('Not currently eligible'));

        $heroCtaLabel = null;
        $heroCtaUrl = null;

        if ($hasPendingOverrideRequest && ! $eligibility['eligible']) {
            $heroTone = 'amber';
            $heroTitle = __('Eligibility review pending');
            $heroSubtitle = __('An administrator is reviewing your loan eligibility request.');
            $heroCtaLabel = __('My loans');
            $heroCtaUrl = $this->memberLoansIndexUrl();
        } elseif ($active > 0) {
            $heroTone = 'sky';
            $heroTitle = __('You have an active loan');
        } elseif ($pending > 0) {
            $heroTone = 'amber';
            $heroTitle = __('Application under review');
        } elseif (! $eligibility['eligible']) {
            $heroTone = 'amber';
            $heroTitle = __('Not eligible for a loan');

            if ($canRequestOverride) {
                $heroCtaLabel = __('Request review');
                $heroCtaUrl = $this->memberLoansIndexUrl(requestOverride: true);
            }
        } else {
            $heroTone = 'success';
            $heroTitle = __('Ready to borrow');
        }

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => $heroTone,
                'title' => $heroTitle,
                'subtitle' => $heroSubtitle,
                'cta_label' => $heroCtaLabel,
                'cta_url' => $heroCtaUrl,
            ],
            'kpis' => InsightKpi::linkMany([
                ['key' => 'pending', 'label' => __('Pending'), 'value' => (string) $pending, 'sub' => __('Requests'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $pending > 0],
                ['key' => 'active', 'label' => __('Active'), 'value' => (string) $active, 'sub' => __('Loans'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'emerald', 'active' => $active > 0],
                ['key' => 'outstanding', 'label' => __('Outstanding'), ...InsightKpi::moneyValue($outstanding, $currency), 'sub' => __('Due'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => $outstanding > 0],
                ['key' => 'completed', 'label' => __('Completed'), 'value' => (string) $completed, 'sub' => __('History'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'teal', 'active' => $completed > 0],
            ], [
                'pending' => $this->memberLoansIndexUrl('pending'),
                'active' => $activeLoan
                    ? $this->memberLoanViewUrl($activeLoan)
                    : $this->memberLoansIndexUrl('active'),
                'outstanding' => $activeLoan
                    ? $this->memberLoanViewUrl($activeLoan)
                    : $this->memberLoansIndexUrl('active'),
                'completed' => $this->memberLoansIndexUrl(),
            ]),
            'active_loan_id' => $activeLoan?->id,
            'eligible' => $eligibility['eligible'],
            'forecast' => [
                'next_emi_amount' => $nextEmiAmount,
                'next_emi_date' => $nextInstallment?->due_date?->format('d M Y'),
                'cash_covers_next_emi' => $cashGap <= 0.0,
                'cash_gap' => $cashGap,
                'next_30_days_amount' => $next30DaysAmount,
                'next_30_days_count' => $next30DaysCount,
                'tone' => $cashGap > 0 ? 'danger' : ($next30DaysCount > 0 ? 'warning' : 'success'),
            ],
            'eligibility' => [
                'eligible' => $eligibility['eligible'],
                'can_request_override' => $canRequestOverride,
                'has_pending_override_request' => $hasPendingOverrideRequest,
                'request_url' => $this->memberLoansIndexUrl(requestOverride: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueLoanRow(Loan $loan, Carbon $now): array
    {
        return [
            'id' => $loan->id,
            'member' => $loan->member?->name ?? '—',
            'amount' => (float) $loan->amount_requested,
            'status' => $loan->status,
            'status_label' => Loan::statusOptions()[$loan->status] ?? $loan->status,
            'queue' => $loan->queue_position,
            'fund_tier' => $loan->fundTier?->label,
            'days_waiting' => $loan->applied_at ? (int) Carbon::parse($loan->applied_at)->diffInDays($now) : 0,
            'is_emergency' => $loan->is_emergency,
            'view_url' => LoanResource::getUrl('view', ['record' => $loan]),
            'edit_url' => LoanResource::getUrl('edit', ['record' => $loan]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array{
     *     pending_members: int,
     *     collected_count: int,
     *     collected_amount: float,
     *     total_pending_emis: int,
     *     ready_with_cash: int,
     *     ready_cash_total: float,
     *     required_cash_total: float,
     *     uncovered_amount: float,
     *     collection_rate: int
     * }
     */
    private function aggregateEmiCollectMetrics(LoanEmiCollectionCatalogService $catalog, int $month, int $year): array
    {
        $pendingMembers = $catalog->pendingMemberCount($month, $year);
        $collectedCount = $catalog->collectedInstallmentsQuery($month, $year)->count();
        $collectedAmount = (float) $catalog->collectedInstallmentsQuery($month, $year)->sum('amount');
        $totalPendingEmis = 0;
        $readyWithCash = 0;
        $readyCashTotal = 0.0;
        $requiredCashTotal = 0.0;
        $uncoveredAmount = 0.0;

        foreach ($catalog->membersWithCollectableEmisQuery($month, $year)->get() as $member) {
            $pending = $catalog->pendingInstallmentCountForMember($member, $month, $year);

            if ($pending === 0) {
                continue;
            }

            $totalPendingEmis += $pending;
            $requiredCash = $catalog->requiredCashForMember($member, $month, $year);
            $requiredCashTotal += $requiredCash;
            $coveredCash = min(max(0.0, $member->getCashBalance()), $requiredCash);

            if ($catalog->memberHasSufficientCash($member, $month, $year)) {
                $readyWithCash++;
                $readyCashTotal += $requiredCash;
            } else {
                $readyCashTotal += $coveredCash;
                $uncoveredAmount += max(0.0, $requiredCash - $coveredCash);
            }
        }

        $denominator = $collectedCount + $totalPendingEmis;
        $collectionRate = $denominator > 0
            ? (int) round(($collectedCount / $denominator) * 100)
            : 0;

        return [
            'pending_members' => $pendingMembers,
            'collected_count' => $collectedCount,
            'collected_amount' => $collectedAmount,
            'total_pending_emis' => $totalPendingEmis,
            'ready_with_cash' => $readyWithCash,
            'ready_cash_total' => round($readyCashTotal, 2),
            'required_cash_total' => $requiredCashTotal,
            'uncovered_amount' => round($uncoveredAmount, 2),
            'collection_rate' => $collectionRate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emiCollectPreviewRow(
        LoanEmiCollectionCatalogService $catalog,
        Member $member,
        int $month,
        int $year,
    ): array {
        $pending = $catalog->pendingInstallmentCountForMember($member, $month, $year);
        $required = $catalog->requiredCashForMember($member, $month, $year);

        return [
            'member' => $member->name,
            'pending_emis' => $pending,
            'required_cash' => $required,
            'has_cash' => $catalog->memberHasSufficientCash($member, $month, $year),
            'filter_url' => LoanResource::listUrl('emi_collect', [
                'tableSearch' => $member->name,
            ]),
        ];
    }

    private function eligibilityReviewRow(LoanEligibilityOverrideRequest $request, Carbon $now): array
    {
        $gateLabels = LoanEligibilityGate::labels();
        $blockedRules = collect($request->gateKeys())
            ->map(fn (string $gate): string => $gateLabels[$gate] ?? $gate)
            ->take(2)
            ->implode(', ');

        return [
            'id' => $request->id,
            'member' => $request->member?->name ?? '—',
            'blocked_rules' => $blockedRules !== '' ? $blockedRules : '—',
            'days_waiting' => $request->created_at !== null
                ? (int) Carbon::parse($request->created_at)->diffInDays($now)
                : 0,
            'filter_url' => LoanResource::listUrl('eligibility_reviews', [
                'status' => ['value' => 'pending'],
                'member_id' => ['value' => (string) $request->member_id],
            ]),
        ];
    }

    /**
     * @return list<array{status: string, label: string, count: int}>
     */
    private function statusBreakdown(): array
    {
        $counts = Loan::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(Loan::statusOptions())
            ->map(fn (string $label, string $status): array => [
                'status' => $status,
                'label' => $label,
                'count' => (int) ($counts[$status] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * Raw monthly loan counts for stacked volume charts (tenant dashboard).
     *
     * @return list<array{label: string, total: int, active: int, completed: int, pending: int}>
     */
    public function sixMonthLoanVolumeTrend(): array
    {
        return $this->buildSixMonthLoanVolumeBuckets();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sixMonthLoanTrend(): array
    {
        return array_map(
            fn (array $month): array => DualProgressTrendBuilder::buildWorkflowMonthRow(
                $month['label'],
                $month['total'],
                $month['active'] + $month['completed'],
                $month['total'] - $month['pending'],
            ),
            $this->buildSixMonthLoanVolumeBuckets(),
        );
    }

    /**
     * @return list<array{label: string, total: int, active: int, completed: int, pending: int}>
     */
    private function buildSixMonthLoanVolumeBuckets(): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthTotals = [];

        Loan::query()
            ->whereBetween('applied_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['status', 'applied_at'])
            ->each(function (Loan $loan) use (&$monthTotals): void {
                $appliedAt = $loan->applied_at;

                if ($appliedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $appliedAt)->startOfMonth()->format('Y-m');
                $monthTotals[$key] ??= [
                    'total' => 0,
                    'pending' => 0,
                    'active' => 0,
                    'completed' => 0,
                ];
                $monthTotals[$key]['total']++;

                if ($loan->status === 'pending') {
                    $monthTotals[$key]['pending']++;

                    return;
                }

                if ($loan->status === 'active') {
                    $monthTotals[$key]['active']++;

                    return;
                }

                if (in_array($loan->status, ['completed', 'early_settled'], true)) {
                    $monthTotals[$key]['completed']++;
                }
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');

            $trend[] = [
                'label' => $month->format('M'),
                'total' => (int) ($monthTotals[$key]['total'] ?? 0),
                'pending' => (int) ($monthTotals[$key]['pending'] ?? 0),
                'active' => (int) ($monthTotals[$key]['active'] ?? 0),
                'completed' => (int) ($monthTotals[$key]['completed'] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * @return list<int>
     */
    private function weeklyApplicationSparkline(): array
    {
        $now = BusinessDay::now();
        $oldestWeekStart = $now->copy()->subWeeks(7)->startOfWeek();
        $currentWeekEnd = $now->copy()->endOfWeek();
        $weekCounts = [];

        Loan::query()
            ->whereBetween('applied_at', [$oldestWeekStart, $currentWeekEnd])
            ->get(['applied_at'])
            ->each(function (Loan $loan) use (&$weekCounts): void {
                $appliedAt = $loan->applied_at;

                if ($appliedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $appliedAt)->startOfWeek()->toDateString();
                $weekCounts[$key] = ($weekCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek()->toDateString();
            $points[] = $weekCounts[$start] ?? 0;
        }

        return $points;
    }

    /**
     * @return list<array{label: string, used_percent: int, available: float, allocated: float}>
     */
    private function fundTierUtilization(string $currency): array
    {
        return FundTier::query()
            ->where('is_active', true)
            ->orderBy('tier_number')
            ->limit(4)
            ->get()
            ->map(function (FundTier $tier) use ($currency): array {
                $allocated = $tier->allocated_amount;
                $used = $allocated > 0 ? (int) round(($tier->active_exposure / $allocated) * 100) : 0;

                return [
                    'label' => $tier->label,
                    'used_percent' => min(100, $used),
                    'available' => $tier->available_amount,
                    'allocated' => $allocated,
                    'available_formatted' => $this->formatMoneyCompact($tier->available_amount, $currency),
                ];
            })
            ->all();
    }

    private function monthOverMonthChange(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    private function insightsOnMemberPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'member';
    }

    private function memberLoansIndexUrl(?string $status = null, bool $requestOverride = false): string
    {
        $parameters = [];

        if ($status !== null) {
            $parameters['filters'] = ['status' => ['value' => $status]];
        }

        if ($requestOverride) {
            $parameters['requestOverride'] = 1;
        }

        if ($parameters === []) {
            return MyLoanResource::listUrl();
        }

        return MyLoanResource::getUrl('index', $parameters);
    }

    private function memberLoanViewUrl(Loan $loan): string
    {
        return MyLoanResource::getUrl('view', ['record' => $loan]);
    }

    private function formatMoneyCompact(float $amount, string $currency): string
    {
        return MoneyDisplay::compactWithSymbol($amount, $currency);
    }
}
