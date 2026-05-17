<?php

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Tenant\Pages\Dashboard as TenantDashboard;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

it('resolves central admin dashboard stat link targets', function () {
    Filament::setCurrentPanel('admin');

    expect(TenantResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(SubscriptionResource::getUrl('index'))->toBeString()->not->toBeEmpty();
});

it('resolves tenant panel dashboard and resource link targets', function () {
    Filament::setCurrentPanel('tenant');

    expect(TenantDashboard::getUrl())->toBeString()->not->toBeEmpty()
        ->and(MasterAccountResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(MemberResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(ContributionResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(LoanResource::getUrl('index'))->toBeString()->not->toBeEmpty();
});

it('resolves member panel dashboard stat link targets', function () {
    Filament::setCurrentPanel('member');

    expect(MyAccountResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(MyContributionResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(MyLoanResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(Dashboard::getUrl())->toBeString()->not->toBeEmpty();
});
