<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\FiscalYearClosePage;
use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Filament\Tenant\Pages\SystemMaintenancePage;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\SettingsTabRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    App::setLocale('en');
});

test('bank accounts default tab is pending bank match', function () {
    request()->replace([]);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('clearance');
});

test('bank accounts invalid tab falls back to clearance', function () {
    request()->replace(['tab' => 'invalid']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('clearance');
});

test('settings tab registry includes reconciliation and fund tiers', function () {
    $tabs = SettingsTabRegistry::tabs();

    expect($tabs)->toHaveKeys([
        'general::tab',
        'collection::tab',
        'reconciliation::tab',
        'fund-tiers::tab',
        'guarantor-rules::tab',
    ]);
});

test('audit system page exposes navigation label', function () {
    expect(AuditSystemPage::getNavigationLabel())->toBe('Audit & System');
});

test('audit system page embeds maintenance workspace for tenant admin', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Audit Admin',
        'email' => 'audit-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'maintenance'])
        ->assertSuccessful()
        ->assertSee(__('Database backups'))
        ->assertSee(__('Save backup to server'));
});

test('audit system page embeds migration workspace for tenant admin', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Migration Admin',
        'email' => 'migration-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'migration'])
        ->assertSuccessful()
        ->assertSee(__('Migration steps'))
        ->assertSee(__('Recommended approach'));
});

test('audit system page embeds fiscal year close workspace', function () {
    Filament::setCurrentPanel('tenant');

    $user = User::create([
        'name' => 'Fiscal User',
        'email' => 'fiscal-user@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    Livewire::actingAs($user, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'fiscal'])
        ->assertSuccessful()
        ->assertSee(__('Close workflow'))
        ->assertSee(__('Run readiness checks'));
});

test('audit system page hides admin-only side tabs for non-admin users', function () {
    Filament::setCurrentPanel('tenant');

    $user = User::create([
        'name' => 'Audit Viewer',
        'email' => 'audit-viewer@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $component = Livewire::actingAs($user, 'tenant')
        ->test(AuditSystemPage::class)
        ->assertSuccessful();

    expect(array_keys($component->instance()->getSideTabOptions()))
        ->not->toContain('maintenance')
        ->not->toContain('migration');

    Livewire::actingAs($user, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'maintenance'])
        ->assertSet('sideTab', 'audit');
});

test('embedded maintenance panel uses minimal layout view', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Embedded Maintenance Admin',
        'email' => 'embedded-maintenance@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(SystemMaintenancePage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Database backups'))
        ->assertSet('embedded', true);
});

test('embedded migration panel renders wizard steps', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Embedded Migration Admin',
        'email' => 'embedded-migration@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Upload files & settings'))
        ->assertSet('embedded', true);
});

test('embedded fiscal close panel renders readiness placeholder', function () {
    Filament::setCurrentPanel('tenant');

    $user = User::create([
        'name' => 'Embedded Fiscal User',
        'email' => 'embedded-fiscal@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    Livewire::actingAs($user, 'tenant')
        ->test(FiscalYearClosePage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Run readiness checks to see whether this tenant can close books for the selected period.'))
        ->assertSet('embedded', true);
});
