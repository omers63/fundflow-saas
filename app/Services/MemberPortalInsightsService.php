<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\MyProfilePage;
use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Services\Concerns\EnrichesMemberPortalDashboard;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\LoanSettings;
use App\Support\PublicPageSettings;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;

final class MemberPortalInsightsService
{
    use EnrichesMemberPortalDashboard;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $member->loadMissing(['cashAccount', 'fundAccount', 'user', 'parent', 'dependents']);
        $member = $member->fresh() ?? $member;

        $currency = InsightFormatter::currency();
        $loanService = app(LoanService::class);
        $delinquency = app(LoanDelinquencyService::class);

        $cashBalance = $member->getCashBalance();
        $fundBalance = $member->getFundBalance();
        $eligibility = $loanService->checkEligibility($member);
        $arrears = $delinquency->memberArrearsSummary($member);

        $activeLoan = $member->loans()->active()->with('installments')->latest('applied_at')->first();
        $pendingLoan = $member->loans()->where('status', 'pending')->exists();
        $loanOutstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;

        $installmentsTotal = $activeLoan?->installments->count() ?? 0;
        $installmentsPaid = $activeLoan?->installments->where('status', 'paid')->count() ?? 0;
        $installmentsOverdue = $activeLoan?->installments->where('status', 'overdue')->count() ?? 0;
        $repayPercent = $installmentsTotal > 0
            ? (int) round(($installmentsPaid / $installmentsTotal) * 100)
            : 0;

        $pendingDeposits = (int) FundPosting::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->count();

        $unreadMessages = (int) DirectMessage::query()
            ->where('to_user_id', $member->user_id)
            ->whereNull('read_at')
            ->whereHas('sender', fn ($q) => $q->where('is_admin', true))
            ->count();

        $latestStatement = MonthlyStatement::query()
            ->where('member_id', $member->id)
            ->latest('period')
            ->first();

        $cycles = app(ContributionCycleService::class);
        [$curMonth, $curYear] = $cycles->currentOpenPeriod();
        $currentPeriod = Contribution::periodDate($curMonth, $curYear);
        $contributionMetrics = Contribution::query()
            ->where('member_id', $member->id)
            ->selectRaw("SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'posted' THEN amount ELSE 0 END), 0) as posted_total")
            ->selectRaw(
                "MAX(CASE WHEN status = 'posted' AND period = ? THEN 1 ELSE 0 END) as posted_this_cycle",
                [$currentPeriod],
            )
            ->first();

        $postedThisCycle = (int) ($contributionMetrics?->posted_this_cycle ?? 0) === 1;
        $monthly = (float) $member->monthly_contribution_amount;
        $contributionsPosted = (int) ($contributionMetrics?->posted_count ?? 0);
        $contributionsPostedTotal = (float) ($contributionMetrics?->posted_total ?? 0.0);
        $dependentsCount = $member->dependents()->count();
        $guaranteedLoansCount = (int) Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->whereIn('status', ['pending', 'approved', 'disbursed', 'repaying', 'defaulted'])
            ->count();

        $cycleStatus = $this->resolveMemberCycleStatus(
            $member,
            $postedThisCycle,
            $cycles,
            $curMonth,
            $curYear,
        );
        $underLoanRepayment = $member->hasActiveLoanRepaymentObligation();
        $requiredCash = $underLoanRepayment
            ? 0.0
            : $cycles->requiredCashForMemberPeriod($member, $curMonth, $curYear);
        $sparkline = $this->contributionSparkline($member);
        $trend = $this->sixMonthContributionTrend($member);

        $firstName = trim(explode(' ', $member->name)[0] ?: $member->name);

        $overrideRequests = app(LoanEligibilityOverrideRequestService::class);
        $canRequestOverride = $overrideRequests->canSubmit($member);
        $hasPendingOverrideRequest = $overrideRequests->pendingRequestFor($member) !== null;

        $hero = $this->buildHero(
            $member,
            $arrears,
            $activeLoan,
            $eligibility,
            $pendingDeposits,
            $unreadMessages,
            $canRequestOverride,
            $hasPendingOverrideRequest,
        );

        return [
            'member' => [
                'name' => $member->name,
                'first_name' => $firstName,
                'number' => $member->member_number,
                'status' => $member->status,
                'status_label' => Member::statusOptions()[$member->status] ?? $member->status,
            ],
            'currency' => $currency,
            'greeting' => $this->buildGreeting(
                $member,
                $hero,
                $cashBalance,
                $fundBalance,
                $cycles,
                $curMonth,
                $curYear,
                $postedThisCycle,
                $unreadMessages,
                $pendingDeposits,
                $arrears,
            ),
            'hero' => $hero,
            'kpis' => $this->buildKpis(
                $member,
                $cashBalance,
                $fundBalance,
                $loanOutstanding,
                $contributionsPosted,
                $pendingDeposits,
                $unreadMessages,
            ),
            'loan_card' => $activeLoan ? [
                'id' => $activeLoan->id,
                'status_label' => Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status,
                'outstanding' => InsightFormatter::money($loanOutstanding),
                'repay_percent' => $repayPercent,
                'installments' => $installmentsPaid.'/'.$installmentsTotal,
                'installments_paid' => $installmentsPaid,
                'installments_total' => $installmentsTotal,
                'overdue_count' => $installmentsOverdue,
                'view_url' => MyLoanResource::getUrl('view', ['record' => $activeLoan]),
            ] : null,
            'eligibility' => [
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reasons'][0] ?? null,
                'max_amount' => InsightFormatter::money(LoanSettings::maxLoanAmountForMember($fundBalance)),
                'can_request_override' => $canRequestOverride,
                'has_pending_override_request' => $hasPendingOverrideRequest,
                'request_url' => MyLoanResource::getUrl('index', ['requestOverride' => 1]),
            ],
            'cycle' => [
                'period_label' => $cycles->periodLabel($curMonth, $curYear),
                'period' => $cycles->periodLabel($curMonth, $curYear),
                'posted' => $postedThisCycle,
                'status_key' => $cycleStatus['key'],
                'status_label' => $cycleStatus['label'],
                'status_tone' => $cycleStatus['tone'],
                'under_loan_repayment' => $underLoanRepayment,
                'loan_repayment_message' => $underLoanRepayment
                    ? __('Under loan repayment')
                    : null,
                'required_cash' => InsightFormatter::money($requiredCash),
                'contributions_url' => MyContributionResource::getUrl('index'),
            ],
            'steps' => $this->memberLifecycleSteps($member, $postedThisCycle, $activeLoan, $arrears),
            'arrears' => [
                'visible' => $arrears['has_arrears'] || $arrears['is_delinquent'],
                'is_delinquent' => $arrears['is_delinquent'],
                'overdue_installments' => $arrears['overdue_installment_count'],
                'unpaid_periods' => $arrears['unpaid_contribution_periods'],
                'loans_url' => MyLoanResource::getUrl('index'),
                'contributions_url' => MyContributionResource::getUrl('index'),
            ],
            'fund_summary' => [
                'contributions_count' => $contributionsPosted,
                'contributions_total' => InsightFormatter::money($contributionsPostedTotal),
                'pending_postings' => $pendingDeposits,
                'fund_minimum_pct' => $monthly > 0
                    ? min(100, (int) round(($fundBalance / $monthly) * 100))
                    : null,
                'monthly_contribution' => InsightFormatter::money($monthly),
            ],
            'trend' => $trend,
            'recent_activity' => $this->recentMemberTransactions($member),
            'recent_contributions' => $this->recentMemberContributions($member),
            'relation_summaries' => $this->memberRelationSummaries(
                $member,
                $contributionsPosted,
                $contributionsPostedTotal,
                $pendingDeposits,
                $activeLoan,
                $loanOutstanding,
                $dependentsCount,
                $guaranteedLoansCount,
                $unreadMessages,
                $latestStatement !== null,
            ),
            'household' => $this->memberHousehold($member),
            'latest_statement' => $this->latestStatementCard($latestStatement),
            'quick_actions' => $this->quickActions(
                $member,
                $eligibility['eligible'],
                $pendingLoan,
                $latestStatement !== null,
                $guaranteedLoansCount > 0,
                $unreadMessages,
                $canRequestOverride,
                $hasPendingOverrideRequest,
            ),
            'recent_deposits' => FundPosting::query()
                ->where('member_id', $member->id)
                ->latest()
                ->limit(4)
                ->get()
                ->map(fn (FundPosting $posting): array => [
                    'amount' => InsightFormatter::money((float) $posting->amount),
                    'status' => $posting->status,
                    'status_label' => match ($posting->status) {
                        'pending' => __('Pending review'),
                        'accepted' => __('Accepted'),
                        'rejected' => __('Rejected'),
                        default => ucfirst($posting->status),
                    },
                    'date' => $posting->posting_date !== null
                        ? Carbon::parse((string) $posting->posting_date)->format('d M Y')
                        : '—',
                    'url' => MyFundPostingResource::getUrl('index'),
                ])
                ->all(),
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'quick_links' => [
                [
                    'label' => __('Contributions'),
                    'url' => MyContributionResource::getUrl('index'),
                    'icon' => 'heroicon-o-banknotes',
                ],
                [
                    'label' => __('Deposits'),
                    'url' => MyFundPostingResource::getUrl('index'),
                    'icon' => 'heroicon-o-inbox-arrow-down',
                ],
                [
                    'label' => __('Loans'),
                    'url' => MyLoanResource::getUrl('index'),
                    'icon' => 'heroicon-o-currency-dollar',
                ],
            ],
        ];
    }

    /**
     * @param  array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}  $hero
     * @param  array{has_arrears: bool, is_delinquent: bool}  $arrears
     * @return array<string, mixed>
     */
    private function buildGreeting(
        Member $member,
        array $hero,
        float $cashBalance,
        float $fundBalance,
        ContributionCycleService $cycles,
        int $curMonth,
        int $curYear,
        bool $postedThisCycle,
        int $unreadMessages,
        int $pendingDeposits,
        array $arrears,
    ): array {
        $now = BusinessDay::now();
        $hour = (int) $now->format('G');
        $periodLabel = match (true) {
            $hour < 12 => __('Good morning'),
            $hour < 17 => __('Good afternoon'),
            default => __('Good evening'),
        };

        $firstName = trim(explode(' ', $member->name)[0] ?: $member->name);
        $user = $member->user;
        $cashKpi = InsightFormatter::moneyKpi($cashBalance);
        $fundKpi = InsightFormatter::moneyKpi($fundBalance);

        $attentionCount = ($unreadMessages > 0 ? 1 : 0)
            + ($pendingDeposits > 0 ? 1 : 0)
            + (! $postedThisCycle ? 1 : 0)
            + (($arrears['has_arrears'] ?? false) || ($arrears['is_delinquent'] ?? false) ? 1 : 0);

        $defaultSubtitle = $attentionCount > 0
            ? trans_choice(
                ':count item needs your attention|:count items need your attention',
                $attentionCount,
                ['count' => $attentionCount],
            )
            : __('Your member portal is up to date — explore your accounts and activity below.');

        $statusTone = match ($member->status) {
            'active' => 'active',
            'delinquent' => 'warning',
            default => 'inactive',
        };

        /** @var list<array{label: string, icon: string, url: ?string, tone: string}> $pills */
        $pills = [];

        if ($arrears['is_delinquent'] ?? false) {
            $pills[] = [
                'label' => __('Account delinquent'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'url' => MyLoanResource::getUrl('index'),
                'tone' => 'danger',
            ];
        } elseif ($arrears['has_arrears'] ?? false) {
            $pills[] = [
                'label' => __('Arrears on record'),
                'icon' => 'heroicon-o-exclamation-circle',
                'url' => MyLoanResource::getUrl('index'),
                'tone' => 'warning',
            ];
        }

        if ($unreadMessages > 0) {
            $pills[] = [
                'label' => trans_choice(':count unread message|:count unread messages', $unreadMessages, ['count' => $unreadMessages]),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'url' => MyMessageResource::getUrl('index'),
                'tone' => 'info',
            ];
        }

        if ($pendingDeposits > 0) {
            $pills[] = [
                'label' => trans_choice(':count deposit pending|:count deposits pending', $pendingDeposits, ['count' => $pendingDeposits]),
                'icon' => 'heroicon-o-inbox-arrow-down',
                'url' => MyFundPostingResource::getUrl('index'),
                'tone' => 'amber',
            ];
        }

        if (! $postedThisCycle) {
            $pills[] = [
                'label' => __('Contribution not posted :period', [
                    'period' => $cycles->periodLabel($curMonth, $curYear),
                ]),
                'icon' => 'heroicon-o-calendar-days',
                'url' => MyContributionResource::getUrl('index'),
                'tone' => 'amber',
            ];
        }

        return [
            'period_label' => $periodLabel,
            'name' => $member->name,
            'first_name' => $firstName,
            'fund_name' => PublicPageSettings::fundName(tenant('name')),
            'date' => $now->locale(app()->getLocale())->translatedFormat('l, F j'),
            'subtitle' => $hero['subtitle'] ?: $defaultSubtitle,
            'highlight_title' => $hero['title'],
            'highlight_cta_label' => $hero['cta_label'],
            'highlight_cta_url' => $hero['cta_url'],
            'highlight_tone' => $hero['tone'],
            'member_number' => $member->member_number,
            'status' => $member->status,
            'status_label' => Member::statusOptions()[$member->status] ?? $member->status,
            'status_tone' => $statusTone,
            'initials' => $this->memberDisplayInitials($member),
            'avatar_url' => $user?->avatarPublicUrl(),
            'profile_url' => MyProfilePage::getUrl(),
            'joined_label' => $member->joined_at
                ? __('Member since :date', [
                    'date' => Carbon::parse((string) $member->joined_at)
                        ->locale(app()->getLocale())
                        ->translatedFormat('M Y'),
                ])
                : null,
            'balances' => [
                [
                    'variant' => 'cash',
                    'label' => __('Cash'),
                    'amount' => $cashKpi['display'],
                    'full' => $cashKpi['full'],
                    'icon' => 'heroicon-o-wallet',
                    'url' => $member->cashAccount
                        ? MyAccountResource::getUrl('view', ['record' => $member->cashAccount])
                        : MyAccountResource::getUrl('index'),
                ],
                [
                    'variant' => 'fund',
                    'label' => __('Fund'),
                    'amount' => $fundKpi['display'],
                    'full' => $fundKpi['full'],
                    'icon' => 'heroicon-o-building-library',
                    'url' => $member->fundAccount
                        ? MyAccountResource::getUrl('view', ['record' => $member->fundAccount])
                        : MyAccountResource::getUrl('index'),
                ],
            ],
            'pills' => $pills,
        ];
    }

    private function memberDisplayInitials(Member $member): string
    {
        $parts = preg_split('/\s+/u', trim($member->name), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1
            ? mb_substr($parts[array_key_last($parts)], 0, 1)
            : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * @param  array{has_arrears: bool, is_delinquent: bool}  $arrears
     * @param  array{eligible: bool, reasons?: list<string>}  $eligibility
     * @return array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}
     */
    private function buildHero(
        Member $member,
        array $arrears,
        ?Loan $activeLoan,
        array $eligibility,
        int $pendingDeposits,
        int $unreadMessages,
        bool $canRequestOverride = false,
        bool $hasPendingOverrideRequest = false,
    ): array {
        if ($arrears['is_delinquent'] || $arrears['has_arrears']) {
            return [
                'tone' => 'danger',
                'title' => __('Action required on your account'),
                'subtitle' => __('Please review arrears or contact the fund office.'),
                'cta_label' => __('My loans'),
                'cta_url' => MyLoanResource::getUrl('index'),
            ];
        }

        if ($unreadMessages > 0) {
            return [
                'tone' => 'amber',
                'title' => trans_choice(':count new message|:count new messages', $unreadMessages, ['count' => $unreadMessages]),
                'subtitle' => __('Messages from your fund administrators'),
                'cta_label' => __('Open inbox'),
                'cta_url' => MyMessageResource::getUrl('index'),
            ];
        }

        if ($activeLoan !== null) {
            return [
                'tone' => 'sky',
                'title' => __('Active loan in progress'),
                'subtitle' => __('Outstanding :amount', ['amount' => InsightFormatter::money($activeLoan->getOutstandingBalance())]),
                'cta_label' => __('View loan'),
                'cta_url' => MyLoanResource::getUrl('view', ['record' => $activeLoan]),
            ];
        }

        if ($eligibility['eligible']) {
            return [
                'tone' => 'success',
                'title' => __('You are eligible to apply for a loan'),
                'subtitle' => __('Maximum amount :amount', ['amount' => InsightFormatter::money(LoanSettings::maxLoanAmountForMember($member->getFundBalance()))]),
                'cta_label' => __('Apply now'),
                'cta_url' => ApplyForLoan::getUrl(),
            ];
        }

        if ($hasPendingOverrideRequest) {
            return [
                'tone' => 'amber',
                'title' => __('Eligibility review pending'),
                'subtitle' => __('An administrator is reviewing your loan eligibility request.'),
                'cta_label' => __('My loans'),
                'cta_url' => MyLoanResource::getUrl('index'),
            ];
        }

        if ($canRequestOverride) {
            return [
                'tone' => 'amber',
                'title' => __('Not eligible for a loan'),
                'subtitle' => $eligibility['reasons'][0] ?? __('Requirements not met'),
                'cta_label' => __('Request review'),
                'cta_url' => MyLoanResource::getUrl('index', ['requestOverride' => 1]),
            ];
        }

        if ($pendingDeposits > 0) {
            return [
                'tone' => 'amber',
                'title' => trans_choice(':count deposit pending review|:count deposits pending review', $pendingDeposits, ['count' => $pendingDeposits]),
                'subtitle' => __('We will notify you when it is processed'),
                'cta_label' => __('View deposits'),
                'cta_url' => MyFundPostingResource::getUrl('index'),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Welcome back'),
            'subtitle' => __('Your member portal is up to date'),
            'cta_label' => null,
            'cta_url' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        Member $member,
        float $cashBalance,
        float $fundBalance,
        float $loanOutstanding,
        int $contributionsPosted,
        int $pendingDeposits,
        int $unreadMessages,
    ): array {
        $cashKpi = InsightFormatter::moneyKpi($cashBalance);
        $fundKpi = InsightFormatter::moneyKpi($fundBalance);

        return [
            [
                'label' => __('Cash'),
                'value' => $cashKpi['display'],
                'sub' => $cashKpi['full'],
                'icon' => 'heroicon-o-wallet',
                'accent' => 'emerald',
                'url' => $member->cashAccount
                    ? MyAccountResource::getUrl('view', ['record' => $member->cashAccount])
                    : MyAccountResource::getUrl('index'),
            ],
            [
                'label' => __('Fund'),
                'value' => $fundKpi['display'],
                'sub' => $fundKpi['full'],
                'icon' => 'heroicon-o-building-library',
                'accent' => 'indigo',
                'url' => $member->fundAccount
                    ? MyAccountResource::getUrl('view', ['record' => $member->fundAccount])
                    : MyAccountResource::getUrl('index'),
            ],
            [
                'label' => __('Contributions'),
                'value' => (string) $contributionsPosted,
                'sub' => __('Posted'),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'sky',
                'url' => MyContributionResource::getUrl('index'),
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
                'label' => __('Loan'),
                'value' => $loanOutstanding > 0 ? InsightFormatter::compactAmount($loanOutstanding) : '—',
                'sub' => $loanOutstanding > 0 ? InsightFormatter::money($loanOutstanding) : __('No balance due'),
                'icon' => 'heroicon-o-scale',
                'accent' => 'violet',
                'url' => MyLoanResource::getUrl('index'),
            ],
            [
                'label' => __('Messages'),
                'value' => (string) $unreadMessages,
                'sub' => __('Unread'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'accent' => $unreadMessages > 0 ? 'amber' : 'gray',
                'url' => MyMessageResource::getUrl('index'),
            ],
        ];
    }

    /**
     * @return list<array{
     *     label: string,
     *     description: string,
     *     url: string,
     *     icon: string,
     *     tone: string,
     *     badge: ?string,
     *     visible: bool
     * }>
     */
    private function quickActions(
        Member $member,
        bool $eligible,
        bool $pendingLoan,
        bool $hasStatement,
        bool $hasGuaranteedLoans = false,
        int $unreadMessages = 0,
        bool $canRequestOverride = false,
        bool $hasPendingOverrideRequest = false,
    ): array {
        return [
            [
                'label' => __('New deposit'),
                'description' => __('Submit a fund posting'),
                'url' => MyFundPostingResource::getUrl('create'),
                'icon' => 'heroicon-o-plus-circle',
                'tone' => 'deposit',
                'badge' => null,
                'visible' => true,
            ],
            [
                'label' => __('Apply for loan'),
                'description' => __('Check eligibility and apply'),
                'url' => ApplyForLoan::getUrl(),
                'icon' => 'heroicon-o-document-plus',
                'tone' => 'loan',
                'badge' => null,
                'visible' => $eligible && ! $pendingLoan,
            ],
            [
                'label' => __('Request eligibility review'),
                'description' => $hasPendingOverrideRequest
                    ? __('Review pending with admin')
                    : __('Ask admin to review blocked rules'),
                'url' => MyLoanResource::getUrl('index', ['requestOverride' => 1]),
                'icon' => 'heroicon-o-shield-exclamation',
                'tone' => 'loan',
                'badge' => $hasPendingOverrideRequest ? __('Pending') : null,
                'visible' => ! $eligible && ($canRequestOverride || $hasPendingOverrideRequest),
            ],
            [
                'label' => __('Statements'),
                'description' => __('Monthly account summaries'),
                'url' => MyStatementResource::getUrl('index'),
                'icon' => 'heroicon-o-document-text',
                'tone' => 'statements',
                'badge' => null,
                'visible' => $hasStatement,
            ],
            [
                'label' => __('My accounts'),
                'description' => __('Cash, fund, and loans'),
                'url' => MyAccountResource::getUrl('index'),
                'icon' => 'heroicon-o-rectangle-stack',
                'tone' => 'accounts',
                'badge' => null,
                'visible' => true,
            ],
            [
                'label' => __('Messages'),
                'description' => __('Inbox from administrators'),
                'url' => MyMessageResource::getUrl('index'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'tone' => 'messages',
                'badge' => $unreadMessages > 0 ? (string) $unreadMessages : null,
                'visible' => true,
            ],
            [
                'label' => __('Guaranteed loans'),
                'description' => __('Loans you guarantee'),
                'url' => MyGuaranteedLoanResource::getUrl('index'),
                'icon' => 'heroicon-o-shield-check',
                'tone' => 'guaranteed',
                'badge' => null,
                'visible' => $hasGuaranteedLoans,
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function contributionSparkline(Member $member): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthCounts = [];

        Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->where('period', '>=', $oldestMonth->toDateString())
            ->get(['period'])
            ->each(function (Contribution $contribution) use (&$monthCounts): void {
                $period = $contribution->period;

                if ($period === null) {
                    return;
                }

                $key = Carbon::parse((string) $period)->startOfMonth()->format('Y-m');
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth()->format('Y-m');
            $points[] = (int) ($monthCounts[$month] ?? 0);
        }

        return $points;
    }
}
