<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Support\TenantSidebarRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('phase coverage pages are reachable for tenant admin', function (string $page) {
    $admin = User::create([
        'name' => 'Phase Coverage Admin '.md5($page),
        'email' => 'phase-coverage-'.md5($page).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test($page)
        ->assertSuccessful();
})->with([
    DisbursementsPage::class,
    ReportsPage::class,
    ReconciliationOverviewPage::class,
    AuditSystemPage::class,
]);

test('phase coverage resources expose consolidated list routes', function (string $resource) {
    $admin = User::create([
        'name' => 'Phase Resource Admin '.md5($resource),
        'email' => 'phase-resource-'.md5($resource).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test($resource::getPages()['index']->getPage())
        ->assertSuccessful();
})->with([
    MemberResource::class,
    LoanResource::class,
    ContributionResource::class,
    BankAccountsResource::class,
]);

test('hidden sidebar items remain accessible by direct url', function (string $class) {
    $admin = User::create([
        'name' => 'Hidden Nav Admin '.md5($class),
        'email' => 'hidden-nav-'.md5($class).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    expect($class::shouldRegisterNavigation())->toBeFalse()
        ->and($class::canAccess())->toBeTrue();
})->with([
    ...TenantSidebarRegistry::hiddenFromSidebar(),
]);
