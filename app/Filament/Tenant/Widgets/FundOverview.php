<?php

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FundOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $masterAccounts = Account::master()->get()->keyBy('type');
        $bal = fn (string $type): float => (float) ($masterAccounts->get($type)?->balance ?? 0);

        $masterViewUrl = fn (?Account $account): string => $account
            ? MasterAccountResource::getUrl('view', ['record' => $account])
            : MasterAccountResource::getUrl('index');

        $activeMembers = Member::active()->count();
        $pendingContributions = Contribution::pending()->count();
        $activeLoans = Loan::active()->count();

        return [
            Stat::make(__('Master Cash'), '$'.number_format($bal('cash'), 2))
                ->description(__('Cash on hand'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info')
                ->url($masterViewUrl($masterAccounts->get('cash'))),
            Stat::make(__('Master Fund'), '$'.number_format($bal('fund'), 2))
                ->description(__('Total member funds'))
                ->descriptionIcon('heroicon-m-building-library')
                ->color('success')
                ->url($masterViewUrl($masterAccounts->get('fund'))),
            Stat::make(__('Master Bank'), '$'.number_format($bal('bank'), 2))
                ->description(__('Bank balance'))
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary')
                ->url($masterViewUrl($masterAccounts->get('bank'))),
            Stat::make(__('Active Members'), $activeMembers)
                ->description(__('Currently active'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(MemberResource::getUrl('index')),
            Stat::make(__('Pending Contributions'), $pendingContributions)
                ->description(__('Awaiting posting'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingContributions > 0 ? 'warning' : 'success')
                ->url(ContributionResource::getUrl('index')),
            Stat::make(__('Outstanding Loans'), '$'.number_format(
                Loan::active()->get()->sum(fn ($loan) => $loan->getOutstandingBalance()),
                2
            ))
                ->description(__(':count active loan(s)', ['count' => $activeLoans]))
                ->descriptionIcon('heroicon-m-document-text')
                ->color($activeLoans > 0 ? 'warning' : 'success')
                ->url(LoanResource::getUrl('index')),
        ];
    }
}
