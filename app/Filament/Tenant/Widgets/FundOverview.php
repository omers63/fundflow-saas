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
use App\Support\Lang;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FundOverview extends BaseWidget
{
    protected static bool $isDiscovered = false;

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
            Stat::make(Lang::ui('Master Cash'), '$'.number_format($bal('cash'), 2))
                ->description(Lang::ui('Cash on hand'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info')
                ->url($masterViewUrl($masterAccounts->get('cash'))),
            Stat::make(Lang::ui('Master Fund'), '$'.number_format($bal('fund'), 2))
                ->description(Lang::ui('Total member funds'))
                ->descriptionIcon('heroicon-m-building-library')
                ->color('success')
                ->url($masterViewUrl($masterAccounts->get('fund'))),
            Stat::make(Lang::ui('Master Bank'), '$'.number_format($bal('bank'), 2))
                ->description(Lang::ui('Bank balance'))
                ->descriptionIcon('heroicon-m-building-office-2')
                ->color('primary')
                ->url($masterViewUrl($masterAccounts->get('bank'))),
            Stat::make(Lang::ui('Active Members'), $activeMembers)
                ->description(Lang::ui('Currently active'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->url(MemberResource::getUrl('index')),
            Stat::make(Lang::ui('Pending Contributions'), $pendingContributions)
                ->description(Lang::ui('Awaiting posting'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingContributions > 0 ? 'warning' : 'success')
                ->url(ContributionResource::getUrl('index')),
            Stat::make(Lang::ui('Outstanding Loans'), '$'.number_format(
                Loan::active()->get()->sum(fn ($loan) => $loan->getOutstandingBalance()),
                2
            ))
                ->description(Lang::ui(':count active loan(s)', ['count' => $activeLoans]))
                ->descriptionIcon('heroicon-m-document-text')
                ->color($activeLoans > 0 ? 'warning' : 'success')
                ->url(LoanResource::getUrl('index')),
        ];
    }
}
