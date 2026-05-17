<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\Loans\LoanUserFacingStage;
use Carbon\Carbon;

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
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function portfolioSnapshot(): array
    {
        $now = Carbon::now();
        $currency = Setting::get('general', 'currency', 'USD');

        $pending = Loan::query()->pending()->count();
        $needsDecision = Loan::query()->needsDecision()->count();
        $readyToDisburse = Loan::query()->readyToDisburse()->count();
        $awaitingPayout = Loan::query()->awaitingBankPayout()->count();
        $active = Loan::query()->where('status', 'active')->count();
        $completed = Loan::query()->whereIn('status', ['completed', 'early_settled'])->count();
        $queueTotal = $needsDecision + $readyToDisburse + $awaitingPayout;

        $outstanding = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->sum('amount');

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

        $emergencyInQueue = Loan::query()->inQueue()->where('is_emergency', true)->count();

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
                'tone' => $queueTotal > 0 ? 'amber' : 'success',
                'title' => $queueTotal > 0
                    ? __('Loan operations need attention')
                    : __('Loan pipeline is clear'),
                'subtitle' => $queueTotal > 0
                    ? trans_choice(
                        ':decision pending · :disburse ready · :payout awaiting bank',
                        $queueTotal,
                        [
                            'decision' => $needsDecision,
                            'disburse' => $readyToDisburse,
                            'payout' => $awaitingPayout,
                        ]
                    )
                    : __('No loans waiting for decision, disbursement, or bank payout.'),
                'cta_label' => $queueTotal > 0 ? __('Open queue') : null,
                'cta_url' => $queueTotal > 0 ? LoanResource::getUrl('queue') : null,
            ],
            'kpis' => [
                ['label' => __('Pending'), 'value' => (string) $pending, 'sub' => __('Applications'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $pending > 0],
                ['label' => __('Active'), 'value' => (string) $active, 'sub' => __('Repaying'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'emerald', 'active' => true],
                ['label' => __('Outstanding'), 'value' => $this->formatMoneyCompact($outstanding, $currency), 'sub' => __('Portfolio'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => $outstanding > 0],
                ['label' => __('Overdue'), 'value' => (string) $overdueCount, 'sub' => __('Installments'), 'icon' => 'heroicon-o-exclamation-triangle', 'accent' => 'rose', 'active' => $overdueCount > 0, 'value_class' => $overdueCount > 0 ? 'text-rose-600 dark:text-rose-400' : null],
                ['label' => __('New/mo'), 'value' => (string) $newThisMonth, 'sub' => $this->monthOverMonthChange($newThisMonth, $newLastMonth) !== null ? __(':percent%', ['percent' => $this->monthOverMonthChange($newThisMonth, $newLastMonth)]) : now()->format('M'), 'icon' => 'heroicon-o-sparkles', 'accent' => 'sky', 'active' => true, 'mom' => $this->monthOverMonthChange($newThisMonth, $newLastMonth)],
                ['label' => __('Disbursed'), 'value' => $this->formatMoneyCompact($disbursedThisMonth, $currency), 'sub' => __('This month'), 'icon' => 'heroicon-o-arrow-trending-up', 'accent' => 'teal', 'active' => true],
            ],
            'sparkline' => $this->weeklyApplicationSparkline(),
            'pipeline' => [
                'needs_decision' => $needsDecision,
                'ready_to_disburse' => $readyToDisburse,
                'awaiting_payout' => $awaitingPayout,
                'active' => $active,
                'completed' => $completed,
                'approved_month' => $approvedThisMonth,
                'queue_url' => LoanResource::getUrl('queue'),
                'loans_url' => LoanResource::getUrl('index'),
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
                'cta_label' => $totalIssues > 0 ? __('Review delinquency') : null,
                'cta_url' => $totalIssues > 0 ? LoanResource::getUrl('delinquency') : null,
            ],
            'kpis' => [
                ['label' => __('Overdue'), 'value' => (string) $overdueInstallments, 'sub' => __('Installments'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'rose', 'active' => $overdueInstallments > 0, 'value_class' => $overdueInstallments > 0 ? 'text-rose-600 dark:text-rose-400' : null],
                ['label' => __('At risk'), 'value' => (string) $overdueAmount, 'sub' => $this->formatMoneyCompact($overdueAmount, $currency), 'icon' => 'heroicon-o-scale', 'accent' => 'amber', 'active' => $overdueAmount > 0],
                ['label' => __('Arrears'), 'value' => (string) $contributionArrears, 'sub' => trans_choice(':count member|:count members', $contributionArrearsMembers, ['count' => $contributionArrearsMembers]), 'icon' => 'heroicon-o-banknotes', 'accent' => 'amber', 'active' => $contributionArrears > 0],
                ['label' => __('Delinquent'), 'value' => (string) $delinquentMembers, 'sub' => __('Members'), 'icon' => 'heroicon-o-user-minus', 'accent' => 'violet', 'active' => $delinquentMembers > 0],
                ['label' => __('Guarantor'), 'value' => (string) $guarantorTransferred, 'sub' => __('Liability transferred'), 'icon' => 'heroicon-o-shield-exclamation', 'accent' => 'sky', 'active' => $guarantorTransferred > 0],
                ['label' => __('Exposure'), 'value' => (string) $guarantorAtRisk, 'sub' => __('Past grace'), 'icon' => 'heroicon-o-exclamation-circle', 'accent' => 'rose', 'active' => $guarantorAtRisk > 0],
            ],
            'pipeline' => [
                'overdue_installments' => $overdueInstallments,
                'contribution_arrears' => $contributionArrears,
                'guarantor_at_risk' => $guarantorAtRisk,
                'guarantor_transferred' => $guarantorTransferred,
                'delinquent_members' => $delinquentMembers,
                'delinquency_url' => LoanResource::getUrl('delinquency'),
                'delinquency_installments_url' => LoanResource::getUrl('delinquency').'?tab=installments',
                'delinquency_contributions_url' => LoanResource::getUrl('delinquency').'?tab=contributions',
                'delinquency_guarantor_url' => LoanResource::getUrl('delinquency').'?tab=guarantor',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSnapshot(string $activeTab): array
    {
        $now = Carbon::now();
        $currency = Setting::get('general', 'currency', 'USD');

        $needsDecision = Loan::query()->needsDecision()->count();
        $readyToDisburse = Loan::query()->readyToDisburse()->count();
        $awaitingPayout = Loan::query()->awaitingBankPayout()->count();
        $total = $needsDecision + $readyToDisburse + $awaitingPayout;

        $tabQuery = match ($activeTab) {
            'ready_to_disburse' => Loan::query()->readyToDisburse(),
            'awaiting_payout' => Loan::query()->awaitingBankPayout(),
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
                    'awaiting_payout' => __('Awaiting bank payout'),
                    default => __('Applications awaiting decision'),
                },
                'subtitle' => trans_choice(
                    ':count in this tab · :total total in queue',
                    $tabCount,
                    ['count' => $tabCount, 'total' => $total]
                ),
                'cta_label' => __('All loans'),
                'cta_url' => LoanResource::getUrl('index'),
            ],
            'kpis' => [
                ['label' => __('Decision'), 'value' => (string) $needsDecision, 'sub' => __('Pending'), 'icon' => 'heroicon-o-clipboard-document-check', 'accent' => 'amber', 'active' => $needsDecision > 0],
                ['label' => __('Disburse'), 'value' => (string) $readyToDisburse, 'sub' => __('Approved'), 'icon' => 'heroicon-o-currency-dollar', 'accent' => 'sky', 'active' => $readyToDisburse > 0],
                ['label' => __('Payout'), 'value' => (string) $awaitingPayout, 'sub' => __('Bank'), 'icon' => 'heroicon-o-building-library', 'accent' => 'indigo', 'active' => $awaitingPayout > 0],
                ['label' => __('Tab total'), 'value' => $this->formatMoneyCompact($tabExposure, $currency), 'sub' => __('Exposure'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => true],
                ['label' => __('Emergency'), 'value' => (string) $emergency, 'sub' => __('In queue'), 'icon' => 'heroicon-o-bolt', 'accent' => 'rose', 'active' => $emergency > 0],
                ['label' => __('Queue'), 'value' => (string) $total, 'sub' => __('All stages'), 'icon' => 'heroicon-o-queue-list', 'accent' => 'teal', 'active' => true],
            ],
            'pipeline' => [
                'needs_decision' => $needsDecision,
                'ready_to_disburse' => $readyToDisburse,
                'awaiting_payout' => $awaitingPayout,
                'queue_url' => LoanResource::getUrl('queue'),
            ],
            'preview' => $preview,
            'tab_labels' => [
                'needs_decision' => __('Needs decision'),
                'ready_to_disburse' => __('Ready to disburse'),
                'awaiting_payout' => __('Awaiting bank payout'),
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
            'kpis' => [
                ['label' => __('Active'), 'value' => (string) $activeTiers->count(), 'sub' => __('Tiers'), 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'emerald', 'active' => true],
                ['label' => __('Inactive'), 'value' => (string) $inactiveCount, 'sub' => __('Tiers'), 'icon' => 'heroicon-o-pause-circle', 'accent' => 'violet', 'active' => $inactiveCount > 0],
                ['label' => __('In flight'), 'value' => (string) array_sum($loansByTier->all()), 'sub' => __('Loans'), 'icon' => 'heroicon-o-document-text', 'accent' => 'sky', 'active' => true],
                ['label' => __('Min band'), 'value' => $activeTiers->isNotEmpty() ? $this->formatMoneyCompact((float) $activeTiers->min('min_amount'), $currency) : '—', 'sub' => __('Lowest'), 'icon' => 'heroicon-o-arrow-down', 'accent' => 'teal', 'active' => true],
                ['label' => __('Max band'), 'value' => $activeTiers->isNotEmpty() ? $this->formatMoneyCompact((float) $activeTiers->max('max_amount'), $currency) : '—', 'sub' => __('Highest'), 'icon' => 'heroicon-o-arrow-up', 'accent' => 'violet', 'active' => true],
                ['label' => __('Fund pools'), 'value' => (string) FundTier::query()->where('is_active', true)->count(), 'sub' => __('Linked'), 'icon' => 'heroicon-o-circle-stack', 'accent' => 'indigo', 'active' => true],
            ],
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
            'kpis' => [
                ['label' => __('Master fund'), 'value' => $this->formatMoneyCompact($masterBalance, $currency), 'sub' => __('Balance'), 'icon' => 'heroicon-o-building-library', 'accent' => 'indigo', 'active' => true],
                ['label' => __('Allocated'), 'value' => $this->formatMoneyCompact($totalAllocated, $currency), 'sub' => __('Pools'), 'icon' => 'heroicon-o-circle-stack', 'accent' => 'sky', 'active' => true],
                ['label' => __('Deployed'), 'value' => $this->formatMoneyCompact($totalExposure, $currency), 'sub' => __('Exposure'), 'icon' => 'heroicon-o-arrow-trending-up', 'accent' => 'amber', 'active' => $totalExposure > 0],
                ['label' => __('Available'), 'value' => $this->formatMoneyCompact($totalAvailable, $currency), 'sub' => __('Headroom'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'emerald', 'active' => true],
                ['label' => __('Utilization'), 'value' => $utilization.'%', 'sub' => __('Portfolio'), 'icon' => 'heroicon-o-chart-pie', 'accent' => 'violet', 'active' => true],
                ['label' => __('Active tiers'), 'value' => (string) $tiers->count(), 'sub' => __('Pools'), 'icon' => 'heroicon-o-squares-2x2', 'accent' => 'teal', 'active' => true],
            ],
            'breakdown' => $breakdown,
            'max_used' => $maxUsed,
            'utilization' => $utilization,
            'fund_tiers_url' => FundTierResource::getUrl('index'),
            'queue_url' => LoanResource::getUrl('queue'),
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

        return [
            'currency' => $currency,
            'steps' => LoanUserFacingStage::stepperFor($loan),
            'member_name' => $loan->member?->name,
            'status' => $loan->status,
            'status_label' => Loan::statusOptions()[$loan->status] ?? $loan->status,
            'is_emergency' => $loan->is_emergency,
            'hero' => [
                'tone' => $installmentsOverdue > 0 ? 'danger' : ($loan->status === 'pending' ? 'amber' : 'success'),
                'title' => __(':member · Loan #:id', ['member' => $loan->member?->name ?? '—', 'id' => $loan->id]),
                'subtitle' => Loan::statusOptions()[$loan->status] ?? $loan->status,
                'cta_label' => $loan->status === 'pending' ? __('Open queue') : null,
                'cta_url' => $loan->status === 'pending' ? LoanResource::getUrl('queue') : null,
            ],
            'kpis' => [
                ['label' => __('Requested'), 'value' => $this->formatMoneyCompact((float) $loan->amount_requested, $currency), 'sub' => __('Application'), 'icon' => 'heroicon-o-document-text', 'accent' => 'sky', 'active' => true],
                ['label' => __('Approved'), 'value' => $loan->amount_approved ? $this->formatMoneyCompact($approved, $currency) : '—', 'sub' => __('Terms'), 'icon' => 'heroicon-o-check-badge', 'accent' => 'emerald', 'active' => (bool) $loan->amount_approved],
                ['label' => __('Disbursed'), 'value' => $this->formatMoneyCompact($disbursed, $currency), 'sub' => $disbursePercent.'%', 'icon' => 'heroicon-o-banknotes', 'accent' => 'indigo', 'active' => $disbursed > 0],
                ['label' => __('Outstanding'), 'value' => $this->formatMoneyCompact($outstanding, $currency), 'sub' => __('Balance'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => $outstanding > 0],
                ['label' => __('Queue'), 'value' => $loan->queue_position ? '#'.$loan->queue_position : '—', 'sub' => $loan->fundTier?->label ?? '—', 'icon' => 'heroicon-o-queue-list', 'accent' => 'amber', 'active' => (bool) $loan->queue_position],
                ['label' => __('Schedule'), 'value' => $installmentsTotal > 0 ? $installmentsPaid.'/'.$installmentsTotal : '—', 'sub' => $repayPercent.'% '.__('paid'), 'icon' => 'heroicon-o-calendar-days', 'accent' => 'teal', 'active' => $installmentsTotal > 0],
            ],
            'progress' => [
                'disburse' => ['percent' => $disbursePercent, 'label' => __('Ledger disbursement')],
                'repay' => ['percent' => $repayPercent, 'label' => __('Repayment schedule')],
            ],
            'next_due' => $nextInstallment ? [
                'amount' => $this->formatMoneyCompact((float) $nextInstallment->amount, $currency),
                'date' => $nextInstallment->due_date?->format('d M Y'),
                'status' => $nextInstallment->status,
                'is_overdue' => $nextInstallment->status === 'overdue',
            ] : null,
            'relation_summaries' => [
                [
                    'key' => 'installments',
                    'label' => __('Repayment schedule'),
                    'value' => $installmentsTotal > 0 ? $installmentsPaid.' / '.$installmentsTotal.' '.__('paid') : __('Not generated'),
                    'hint' => $installmentsOverdue > 0 ? trans_choice(':count overdue|:count overdue', $installmentsOverdue, ['count' => $installmentsOverdue]) : ($nextInstallment ? __('Next :date', ['date' => $nextInstallment->due_date?->format('d M')]) : null),
                    'accent' => $installmentsOverdue > 0 ? 'rose' : 'teal',
                    'icon' => 'heroicon-o-calendar-days',
                ],
                [
                    'key' => 'disbursements',
                    'label' => __('Disbursements'),
                    'value' => $this->formatMoneyCompact($disbursed, $currency).' / '.$this->formatMoneyCompact($approved ?: (float) $loan->amount_requested, $currency),
                    'hint' => trans_choice(':count tranche|:count tranches', $loan->disbursements->count(), ['count' => $loan->disbursements->count()]),
                    'accent' => 'indigo',
                    'icon' => 'heroicon-o-arrow-down-tray',
                ],
                [
                    'key' => 'repayments',
                    'label' => __('Manual repayments'),
                    'value' => $this->formatMoneyCompact((float) $loan->repayments->sum('amount'), $currency),
                    'hint' => __('Legacy rows · schedule drives balance'),
                    'accent' => 'violet',
                    'icon' => 'heroicon-o-receipt-refund',
                ],
            ],
            'guarantor' => $loan->guarantor ? [
                'name' => $loan->guarantor->name,
                'released' => $loan->isGuarantorReleased(),
                'liability_transferred' => $loan->guarantor_liability_transferred_at !== null,
            ] : null,
            'late_fees' => $lateFees,
            'view_url' => LoanResource::getUrl('view', ['record' => $loan]),
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

        $activeLoan = (clone $base)->where('status', 'active')->latest('applied_at')->first();

        return [
            'currency' => $currency,
            'hero' => [
                'tone' => $pending > 0 ? 'amber' : ($active > 0 ? 'sky' : 'success'),
                'title' => $active > 0
                    ? __('You have an active loan')
                    : ($pending > 0 ? __('Application under review') : __('Ready to borrow')),
                'subtitle' => $eligibility['eligible']
                    ? __('You may apply for a new loan when eligible.')
                    : ($eligibility['reason'] ?? __('Not currently eligible')),
                'cta_label' => null,
                'cta_url' => null,
            ],
            'kpis' => [
                ['label' => __('Pending'), 'value' => (string) $pending, 'sub' => __('Requests'), 'icon' => 'heroicon-o-clock', 'accent' => 'amber', 'active' => $pending > 0],
                ['label' => __('Active'), 'value' => (string) $active, 'sub' => __('Loans'), 'icon' => 'heroicon-o-banknotes', 'accent' => 'emerald', 'active' => $active > 0],
                ['label' => __('Outstanding'), 'value' => $this->formatMoneyCompact($outstanding, $currency), 'sub' => __('Due'), 'icon' => 'heroicon-o-scale', 'accent' => 'violet', 'active' => $outstanding > 0],
                ['label' => __('Completed'), 'value' => (string) $completed, 'sub' => __('History'), 'icon' => 'heroicon-o-check-circle', 'accent' => 'teal', 'active' => $completed > 0],
            ],
            'active_loan_id' => $activeLoan?->id,
            'eligible' => $eligibility['eligible'],
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
     * @return list<array{label: string, total: int, active: int, completed: int, pending: int}>
     */
    private function sixMonthLoanTrend(): array
    {
        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();

            $row = Loan::query()
                ->whereYear('applied_at', $month->year)
                ->whereMonth('applied_at', $month->month)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status IN ('completed', 'early_settled') THEN 1 ELSE 0 END) as completed
                ")
                ->first();

            $trend[] = [
                'label' => $month->format('M'),
                'total' => (int) ($row->total ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'active' => (int) ($row->active ?? 0),
                'completed' => (int) ($row->completed ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * @return list<int>
     */
    private function weeklyApplicationSparkline(): array
    {
        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i)->startOfWeek();
            $end = $start->copy()->endOfWeek();

            $points[] = Loan::query()
                ->whereBetween('applied_at', [$start, $end])
                ->count();
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

    private function formatMoneyCompact(float $amount, string $currency): string
    {
        if ($amount >= 1_000_000) {
            return number_format($amount / 1_000_000, 1).'M '.$currency;
        }

        if ($amount >= 1_000) {
            return number_format($amount / 1_000, 1).'K '.$currency;
        }

        return number_format($amount, 0).' '.$currency;
    }
}
