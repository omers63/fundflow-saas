<?php

declare(strict_types=1);

use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Support\TenantSidebarRegistry;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

uses(TestCase::class);

test('consolidated sidebar hides moved operational and finance items', function () {
    foreach (TenantSidebarRegistry::hiddenFromSidebar() as $class) {
        expect($class::shouldRegisterNavigation())->toBeFalse();
    }
});

test('consolidated sidebar keeps primary navigation entries visible', function (string $class) {
    expect($class::shouldRegisterNavigation())->toBeTrue();
})->with(array_values(array_filter(
    TenantSidebarRegistry::consolidatedNavigation(),
    fn (string $class): bool => ! in_array($class, [LoansCluster::class, ReconciliationOverviewPage::class], true),
)));

test('jobs page is hidden in favour of audit and system workspace', function () {
    expect(JobsPage::shouldRegisterNavigation())->toBeFalse();
});

test('consolidated sidebar label catalogue matches plan in english locale', function () {
    App::setLocale('en');

    expect(TenantSidebarRegistry::consolidatedNavigationLabels())->toBe([
        'Members',
        'Loans',
        'Collections',
        'Disbursements',
        'Bank Clearing',
        'Reconciliation',
        'Reports',
        'Audit & System',
        'Settings',
    ]);
});
