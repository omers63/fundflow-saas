<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Filament\Tenant\Support\TenantNavigation;

test('tenant navigation group keys are accounts fund management then system', function () {
    expect(TenantNavigation::GROUP_ACCOUNTS)->toBe('Accounts')
        ->and(TenantNavigation::GROUP_FUND_MANAGEMENT)->toBe('Fund Management')
        ->and(TenantNavigation::GROUP_SYSTEM)->toBe('System')
        ->and(TenantNavigation::SORT_DEPOSITS)->toBeLessThan(TenantNavigation::SORT_CONTRIBUTIONS)
        ->and(TenantNavigation::SORT_STATEMENTS)->toBeLessThan(TenantNavigation::SORT_RECONCILIATION)
        ->and(TenantNavigation::groupKeys())->toBe([
            TenantNavigation::GROUP_ACCOUNTS,
            TenantNavigation::GROUP_FUND_MANAGEMENT,
            TenantNavigation::GROUP_SYSTEM,
        ]);
});

test('contribution cycles page is hidden from navigation', function () {
    expect(ContributionCyclePage::shouldRegisterNavigation())->toBeFalse();
});

test('reconciliation is last under fund management navigation group', function () {
    expect(ReconciliationExceptionResource::getNavigationGroup())->toBe(TenantNavigation::GROUP_FUND_MANAGEMENT)
        ->and(ReconciliationExceptionResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ReconciliationExceptionResource::getNavigationSort())->toBe(TenantNavigation::SORT_RECONCILIATION);
});

test('loan overrides are hidden from navigation', function () {
    expect(LoanEligibilityOverrideResource::shouldRegisterNavigation())->toBeFalse();
});

test('tenant navigation group labels follow the active locale', function () {
    App::setLocale('en');

    expect(TenantNavigation::groupLabel(TenantNavigation::GROUP_SYSTEM))->toBe('System')
        ->and(TenantNavigation::groupLabel(TenantNavigation::GROUP_FUND_MANAGEMENT))->toBe('Fund Management');

    App::setLocale('ar');

    expect(TenantNavigation::groupLabel(TenantNavigation::GROUP_SYSTEM))->toBe('النظام');
});
