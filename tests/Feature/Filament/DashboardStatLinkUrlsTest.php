<?php

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\Dashboard as TenantDashboard;
use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\SettingsTabRegistry;
use Filament\Facades\Filament;

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
        ->and(LoanResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']))->toContain('reconciliation')
        ->and(AuditSystemPage::getUrl(['sideTab' => 'audit']))->toContain('sideTab=audit')
        ->and(AuditSystemPage::getUrl(['sideTab' => 'jobs']))->toContain('sideTab=jobs')
        ->and(SettingsTabRegistry::url('fund-tiers::tab'))->toContain('settingsTab=')
        ->and(SettingsTabRegistry::url('fund-tiers::tab'))->toContain('fund-tiers')
        ->and(JobsPage::getUrl())->toContain('/admin/jobs');
});

it('resolves member panel dashboard stat link targets', function () {
    Filament::setCurrentPanel('member');

    expect(MyAccountResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(MyContributionResource::getUrl('index'))->toBeString()->not->toBeEmpty()
        ->and(MyLoanResource::getUrl('index'))->toBeString()->not->toBeEmpty();
});
