<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Transaction;
use App\Services\ContributionCycleService;
use App\Support\Insights\InsightFormatter;
use App\Support\LoanSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class FundAccountPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Fund account';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_MY_ACCOUNTS;

    protected static ?int $navigationSort = MemberNavigation::SORT_FUND_ACCOUNT;

    protected static ?string $slug = 'fund-account';

    protected string $view = 'filament.member.pages.fund-account';

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Fund account');
    }

    public function getSubheading(): ?string
    {
        return __('Your long-term fund savings and loan headroom.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $member = CurrentMember::get();
        $currency = InsightFormatter::currency();
        $fundBalance = $member?->getFundBalance() ?? 0.0;
        $monthly = (float) ($member?->monthly_contribution_amount ?? 0);
        $maxLoan = LoanSettings::maxLoanAmountForMember($fundBalance);

        $cycles = app(ContributionCycleService::class);
        [$openMonth, $openYear] = $cycles->currentOpenPeriod();
        $postedThisCycle = $member !== null && Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($openMonth, $openYear)
            ->posted()
            ->exists();

        $pendingDeposits = $member !== null
            ? (int) FundPosting::query()->where('member_id', $member->id)->where('status', 'pending')->count()
            : 0;

        $contributionsTotal = $member !== null
            ? (float) Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'posted')
                ->sum('amount')
            : 0.0;

        $fundAccountId = $member?->fundAccount?->id;
        $loanFundDebits = $fundAccountId !== null
            ? (float) Transaction::query()
                ->where('account_id', $fundAccountId)
                ->where('type', 'debit')
                ->where('reference_type', 'like', 'loan%')
                ->sum('amount')
            : 0.0;

        $isExempt = $member !== null && $member->isExemptFromContributions($openMonth, $openYear);

        return [
            'currency' => $currency,
            'balance' => $fundBalance,
            'monthly' => $monthly,
            'monthlyLabel' => InsightFormatter::money($monthly),
            'maxLoan' => $maxLoan,
            'maxLoanLabel' => InsightFormatter::money($maxLoan),
            'postedThisCycle' => $postedThisCycle,
            'cycleLabel' => $cycles->periodLabel($openMonth, $openYear),
            'pendingDeposits' => $pendingDeposits,
            'contributionsTotal' => $contributionsTotal,
            'contributionsTotalLabel' => InsightFormatter::money($contributionsTotal),
            'loanFundDebits' => $loanFundDebits,
            'loanFundDebitsLabel' => InsightFormatter::money($loanFundDebits),
            'isExempt' => $isExempt,
            'exemptionLabel' => $isExempt ? __('Exempt this cycle') : __('Contributions apply'),
            'borrowMultiplier' => LoanSettings::maxBorrowMultiplier(),
            'statementsUrl' => MyStatementResource::getUrl('index'),
            'accountId' => $member?->fundAccount?->id,
        ];
    }
}
