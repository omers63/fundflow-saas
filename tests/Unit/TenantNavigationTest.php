<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Pages\MigrationWorkflowPage;
use App\Filament\Tenant\Support\TenantNavigation;

test('tenant navigation group keys are accounts fund management then system', function () {
    expect(TenantNavigation::GROUP_ACCOUNTS)->toBe('Accounts')
        ->and(TenantNavigation::GROUP_FUND_MANAGEMENT)->toBe('Fund Management')
        ->and(TenantNavigation::GROUP_SYSTEM)->toBe('System')
        ->and(TenantNavigation::SORT_DEPOSITS)->toBeLessThan(TenantNavigation::SORT_CONTRIBUTIONS)
        ->and(TenantNavigation::groupKeys())->toBe([
            TenantNavigation::GROUP_ACCOUNTS,
            TenantNavigation::GROUP_FUND_MANAGEMENT,
            TenantNavigation::GROUP_SYSTEM,
        ]);
});

test('contribution cycles page is hidden from navigation', function () {
    expect(ContributionCyclePage::shouldRegisterNavigation())->toBeFalse();
});

test('migrations page is in system group', function () {
    expect(MigrationWorkflowPage::getNavigationGroup())->toBe(TenantNavigation::GROUP_SYSTEM);
});
