<?php

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Member;
use App\Support\Tenant\CurrentMember;
use Filament\Pages\Dashboard;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MyFundOverview extends BaseWidget
{
    protected function getMember(): ?Member
    {
        return CurrentMember::get();
    }

    protected function getStats(): array
    {
        $member = $this->getMember();

        if (! $member) {
            return [
                Stat::make(__('Status'), __('No member profile linked'))
                    ->color('danger')
                    ->url(Dashboard::getUrl()),
            ];
        }

        $fundAccount = $member->fundAccount;
        $cashAccount = $member->cashAccount;
        $fundBalance = $member->getFundBalance();
        $cashBalance = $member->getCashBalance();
        $totalContributions = $member->contributions()->posted()->sum('amount');
        $activeLoan = $member->loans()->active()->first();
        $dependentCount = $member->dependents()->count();

        $stats = [
            Stat::make(__('Fund Balance'), '$'.number_format($fundBalance, 2))
                ->description(__('Your accumulated fund savings'))
                ->descriptionIcon('heroicon-m-building-library')
                ->color('success')
                ->url(
                    $fundAccount
                        ? MyAccountResource::getUrl('view', ['record' => $fundAccount])
                        : MyAccountResource::getUrl('index')
                ),
            Stat::make(__('Cash Balance'), '$'.number_format($cashBalance, 2))
                ->description(__('Available cash'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info')
                ->url(
                    $cashAccount
                        ? MyAccountResource::getUrl('view', ['record' => $cashAccount])
                        : MyAccountResource::getUrl('index')
                ),
            Stat::make(__('Total Contributions'), '$'.number_format((float) $totalContributions, 2))
                ->description(__(':count payments made', ['count' => $member->contributions()->posted()->count()]))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary')
                ->url(MyContributionResource::getUrl('index')),
        ];

        if ($activeLoan) {
            $stats[] = Stat::make(__('Active Loan'), '$'.number_format((float) $activeLoan->amount, 2))
                ->description(__('Outstanding: :amount', ['amount' => '$'.number_format($activeLoan->getOutstandingBalance(), 2)]))
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning')
                ->url(MyLoanResource::getUrl('view', ['record' => $activeLoan]));
        } else {
            $stats[] = Stat::make(__('Loan Eligibility'), $member->isEligibleForLoan() ? __('Eligible') : __('Not Eligible'))
                ->description($member->isEligibleForLoan() ? __('You can apply for a loan') : __('Requirements not met'))
                ->descriptionIcon($member->isEligibleForLoan() ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($member->isEligibleForLoan() ? 'success' : 'gray')
                ->url(MyLoanResource::getUrl('index'));
        }

        if ($dependentCount > 0) {
            $stats[] = Stat::make(__('Dependents'), $dependentCount)
                ->description(__('Members under your sponsorship'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(MyAccountResource::getUrl('index'));
        }

        return $stats;
    }
}
