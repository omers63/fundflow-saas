<?php

declare(strict_types=1);

use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\TenantNavigation;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

test('tenant navigation group keys are finance operations then system', function () {
    expect(TenantNavigation::GROUP_ACCOUNTS)->toBe('Finance')
        ->and(TenantNavigation::GROUP_FUND_MANAGEMENT)->toBe('Operations')
        ->and(TenantNavigation::GROUP_SYSTEM)->toBe('System')
        ->and(TenantNavigation::SORT_MEMBERS)->toBeLessThan(TenantNavigation::SORT_LOANS)
        ->and(TenantNavigation::SORT_LOANS)->toBeLessThan(TenantNavigation::SORT_CONTRIBUTIONS)
        ->and(TenantNavigation::SORT_CONTRIBUTIONS)->toBeLessThan(TenantNavigation::SORT_DISBURSEMENTS)
        ->and(TenantNavigation::SORT_BANK_CLEARING)->toBeLessThan(TenantNavigation::SORT_RECONCILIATION)
        ->and(TenantNavigation::SORT_RECONCILIATION)->toBeLessThan(TenantNavigation::SORT_REPORTS)
        ->and(TenantNavigation::SORT_AUDIT_SYSTEM)->toBeLessThan(TenantNavigation::SORT_SETTINGS)
        ->and(TenantNavigation::groupKeys())->toBe([
            TenantNavigation::GROUP_FUND_MANAGEMENT,
            TenantNavigation::GROUP_ACCOUNTS,
            TenantNavigation::GROUP_SYSTEM,
        ]);
});

test('contribution cycles page is hidden from navigation', function () {
    expect(ContributionCyclePage::shouldRegisterNavigation())->toBeFalse();
});

test('reconciliation lives under finance navigation group', function () {
    expect(ReconciliationOverviewPage::getNavigationGroup())->toBe(TenantNavigation::GROUP_ACCOUNTS)
        ->and(ReconciliationOverviewPage::shouldRegisterNavigation())->toBeTrue()
        ->and(ReconciliationOverviewPage::getNavigationSort())->toBe(TenantNavigation::SORT_RECONCILIATION);
});

test('reports page navigation label follows the active locale', function () {
    App::setLocale('en');

    expect(ReportsPage::getNavigationLabel())->toBe('Reports');

    App::setLocale('ar');

    expect(ReportsPage::getNavigationLabel())->toBe('التقارير');
});

test('loan overrides are hidden from navigation', function () {
    expect(LoanEligibilityOverrideResource::shouldRegisterNavigation())->toBeFalse();
});

test('tenant navigation group labels follow the active locale', function () {
    App::setLocale('en');

    expect(TenantNavigation::groupLabel(TenantNavigation::GROUP_SYSTEM))->toBe('System')
        ->and(TenantNavigation::groupLabel(TenantNavigation::GROUP_FUND_MANAGEMENT))->toBe('Operations')
        ->and(TenantNavigation::groupLabel(TenantNavigation::GROUP_ACCOUNTS))->toBe('Finance');

    App::setLocale('ar');

    expect(TenantNavigation::groupLabel(TenantNavigation::GROUP_SYSTEM))->toBe('النظام');
});

test('tenant account resource navigation labels follow the active locale', function () {
    App::setLocale('ar');

    expect(MasterAccountResource::getNavigationLabel())->toBe('حسابات الإدارة')
        ->and(MasterAccountResource::getPluralModelLabel())->toBe('حسابات الإدارة')
        ->and(AccountResource::getNavigationLabel())->toBe('حسابات الأعضاء')
        ->and(AccountResource::getPluralModelLabel())->toBe('حسابات الأعضاء')
        ->and(MemberResource::getNavigationLabel())->toBe('الأعضاء')
        ->and(MemberResource::getPluralModelLabel())->toBe('الأعضاء')
        ->and(LoansCluster::getNavigationLabel())->toBe('القروض')
        ->and(LoansCluster::getClusterBreadcrumb())->toBe('القروض');
});
