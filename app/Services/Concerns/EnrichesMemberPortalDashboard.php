<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Transaction;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberDateDisplay;
use Illuminate\Support\Str;

trait EnrichesMemberPortalDashboard
{
    /**
     * @param  array{has_arrears: bool, is_delinquent: bool, overdue_installment_count: int, unpaid_contribution_periods: list<string>}  $arrears
     * @return array{key: string, label: string, tone: string, short: string, period?: string}
     */
    protected function resolveMemberCycleStatus(
        Member $member,
        bool $postedThisPeriod,
        ContributionCycleService $cycles,
        int $curMonth,
        int $curYear,
    ): array {
        $period = $cycles->periodLabel($curMonth, $curYear);
        $exempt = $member->isExemptFromContributions();
        $canApply = $cycles->memberCanApplyContributionForPeriod($member, $curMonth, $curYear);
        $requiredCash = $cycles->requiredCashForMemberPeriod($member, $curMonth, $curYear);
        $cashReady = $member->getCashBalance() >= $requiredCash;

        if ($postedThisPeriod) {
            return [
                'key' => 'posted',
                'label' => __('Posted for :period', ['period' => $period]),
                'short' => __('Posted'),
                'tone' => 'emerald',
                'period' => $period,
            ];
        }

        if ($member->hasActiveLoanRepaymentObligation()) {
            $pendingOpenCycleEmi = app(LoanEmiCollectionCatalogService::class)
                ->pendingInstallmentCountForMemberInPeriod($member, $curMonth, $curYear);

            if ($pendingOpenCycleEmi === 0) {
                return [
                    'key' => 'emi_paid',
                    'label' => __('EMI paid for :period', ['period' => $period]),
                    'short' => __('Paid'),
                    'tone' => 'emerald',
                    'period' => $period,
                ];
            }

            return [
                'key' => 'loan_repayment',
                'label' => __('Under loan repayment'),
                'short' => __('Loan EMI'),
                'tone' => 'violet',
                'period' => $period,
            ];
        }

        if ($exempt) {
            return [
                'key' => 'exempt',
                'label' => __('Exempt this cycle'),
                'short' => __('Exempt'),
                'tone' => 'amber',
                'period' => $period,
            ];
        }

        if ($canApply) {
            return [
                'key' => $cashReady ? 'ready' : 'short',
                'label' => $cashReady
                    ? __('Ready · :period', ['period' => $period])
                    : __('Need cash · :period', ['period' => $period]),
                'short' => $cashReady ? __('Ready') : __('Short'),
                'tone' => $cashReady ? 'emerald' : 'rose',
                'period' => $period,
            ];
        }

        if ($member->status !== 'active' || (float) $member->monthly_contribution_amount <= 0) {
            return [
                'key' => 'na',
                'label' => __('Not due this cycle'),
                'short' => __('N/A'),
                'tone' => 'gray',
                'period' => $period,
            ];
        }

        return [
            'key' => 'waiting',
            'label' => __(':period not yet posted', ['period' => $period]),
            'short' => __('Open'),
            'tone' => 'sky',
            'period' => $period,
        ];
    }

    /**
     * @param  array{has_arrears: bool, is_delinquent: bool}  $arrears
     * @return list<array{key: string, label: string, state: string}>
     */
    protected function memberLifecycleSteps(
        Member $member,
        bool $postedThisCycle,
        ?Loan $activeLoan,
        array $arrears,
    ): array {
        $steps = [
            [
                'key' => 'joined',
                'label' => __('Joined'),
                'state' => $member->joined_at ? 'complete' : 'upcoming',
            ],
            [
                'key' => 'active',
                'label' => __('Active'),
                'state' => $member->status === 'active' && ! $arrears['is_delinquent'] ? 'complete' : ($arrears['is_delinquent'] ? 'warning' : 'upcoming'),
            ],
            [
                'key' => 'cycle',
                'label' => __('Cycle'),
                'state' => $postedThisCycle ? 'complete' : ($member->status === 'active' ? 'current' : 'upcoming'),
            ],
        ];

        if ($activeLoan !== null) {
            $steps[] = [
                'key' => 'loan',
                'label' => __('Loan'),
                'state' => 'current',
            ];
        }

        if ($arrears['has_arrears'] || $arrears['is_delinquent']) {
            $steps[] = [
                'key' => 'arrears',
                'label' => __('Arrears'),
                'state' => 'warning',
            ];
        }

        return $steps;
    }

    /**
     * @return list<array{label: string, posted: int}>
     */
    protected function sixMonthContributionTrend(Member $member): array
    {
        return DualProgressTrendBuilder::sixMonthMemberCollectionTrend(
            $member,
            app(ContributionCycleService::class),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentMemberTransactions(Member $member): array
    {
        $accountIds = $member->accounts()->pluck('id');

        if ($accountIds->isEmpty()) {
            return [];
        }

        return Transaction::query()
            ->whereIn('account_id', $accountIds)
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
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentMemberContributions(Member $member): array
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->orderByDesc('posted_at')
            ->limit(5)
            ->get()
            ->map(fn (Contribution $contribution): array => [
                'period' => $contribution->period?->locale(app()->getLocale())->translatedFormat('M Y') ?? '—',
                'amount' => InsightFormatter::money((float) $contribution->amount),
                'posted_at' => $contribution->posted_at?->format('d M'),
                'late' => (bool) $contribution->is_late,
                'url' => MyContributionResource::getUrl('index'),
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: ?string, accent: string, icon: string, url: ?string}>
     */
    protected function memberRelationSummaries(
        Member $member,
        int $contributionsPostedCount,
        float $contributionsPostedTotal,
        int $pendingPostings,
        ?Loan $activeLoan,
        float $loanOutstanding,
        int $dependentsCount,
        int $guaranteedLoansCount,
        int $unreadMessages,
        bool $hasStatement,
    ): array {
        $summaries = [
            [
                'key' => 'contributions',
                'label' => __('Contributions'),
                'value' => (string) $contributionsPostedCount.' '.__('posted'),
                'hint' => InsightFormatter::money($contributionsPostedTotal).' '.__('total'),
                'accent' => 'emerald',
                'icon' => 'heroicon-o-banknotes',
                'url' => MyContributionResource::getUrl('index'),
            ],
            [
                'key' => 'deposits',
                'label' => __('Deposits'),
                'value' => $pendingPostings > 0
                    ? trans_choice(':count pending|:count pending', $pendingPostings, ['count' => $pendingPostings])
                    : __('Up to date'),
                'hint' => __('Submit and track deposit requests'),
                'accent' => $pendingPostings > 0 ? 'amber' : 'teal',
                'icon' => 'heroicon-o-inbox-arrow-down',
                'url' => MyFundPostingResource::getUrl('index'),
            ],
            [
                'key' => 'loans',
                'label' => __('Loans'),
                'value' => $activeLoan
                    ? __('Active · :amount', ['amount' => InsightFormatter::money($loanOutstanding)])
                    : __('No active loan'),
                'hint' => $activeLoan
                    ? (Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status)
                    : null,
                'accent' => $activeLoan ? 'violet' : 'sky',
                'icon' => 'heroicon-o-currency-dollar',
                'url' => $activeLoan
                    ? MyLoanResource::getUrl('view', ['record' => $activeLoan])
                    : MyLoanResource::getUrl('index'),
            ],
            [
                'key' => 'messages',
                'label' => __('Messages'),
                'value' => $unreadMessages > 0
                    ? trans_choice(':count unread|:count unread', $unreadMessages, ['count' => $unreadMessages])
                    : __('Inbox'),
                'hint' => __('Contact fund administrators'),
                'accent' => $unreadMessages > 0 ? 'amber' : 'indigo',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'url' => MyMessageResource::getUrl('index'),
            ],
        ];

        if ($hasStatement) {
            $summaries[] = [
                'key' => 'statements',
                'label' => __('Statements'),
                'value' => __('Monthly PDF'),
                'hint' => __('Download your statements'),
                'accent' => 'indigo',
                'icon' => 'heroicon-o-document-chart-bar',
                'url' => MyStatementResource::getUrl('index'),
            ];
        }

        if ($guaranteedLoansCount > 0) {
            $summaries[] = [
                'key' => 'guaranteed',
                'label' => __('Guaranteed'),
                'value' => trans_choice(':count loan|:count loans', $guaranteedLoansCount, ['count' => $guaranteedLoansCount]),
                'hint' => __('Loans you guarantee'),
                'accent' => 'rose',
                'icon' => 'heroicon-o-shield-check',
                'url' => MyGuaranteedLoanResource::getUrl('index'),
            ];
        }

        if ($dependentsCount > 0 || $member->parent) {
            $summaries[] = [
                'key' => 'household',
                'label' => __('Household'),
                'value' => $member->isParent()
                    ? trans_choice(':count dependent|:count dependents', $dependentsCount, ['count' => $dependentsCount])
                    : ($member->parent?->name ?? __('Member')),
                'hint' => $member->isParent() ? __('Household head') : __('Linked to parent'),
                'accent' => 'sky',
                'icon' => 'heroicon-o-users',
                'url' => MyDependentResource::getUrl('index'),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function latestStatementCard(?MonthlyStatement $statement): ?array
    {
        if ($statement === null) {
            return null;
        }

        return [
            'period' => filled($statement->period)
                ? MemberDateDisplay::westernizeDigits($statement->period_formatted)
                : '—',
            'url' => MyStatementResource::getUrl('index'),
        ];
    }

    /**
     * @return array{dependents: list<array<string, mixed>>, dependents_count: int, parent_name: ?string}
     */
    protected function memberHousehold(Member $member): array
    {
        $member->loadMissing(['parent', 'dependents']);

        return [
            'settings_url' => MemberSettingsPage::getUrl(['tab' => 'profile']),
            'dependents_url' => MyDependentResource::getUrl('index'),
            'dependents' => $member->dependents()
                ->orderBy('name')
                ->limit(6)
                ->get()
                ->map(fn (Member $dependent): array => [
                    'id' => $dependent->id,
                    'name' => $dependent->name,
                    'number' => $dependent->member_number,
                    'status' => Member::statusOptions()[$dependent->status] ?? $dependent->status,
                    'switch_url' => route('tenant.member.dependents.impersonate', ['dependent' => $dependent->id]),
                ])
                ->all(),
            'dependents_count' => $member->dependents()->count(),
            'parent_name' => $member->parent?->name,
        ];
    }
}
