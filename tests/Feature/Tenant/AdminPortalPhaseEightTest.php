<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\FiscalYearClosePage;
use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Pages\SystemMaintenancePage;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\AuditSystemTabRegistry;
use App\Filament\Tenant\Support\ReconciliationTabRegistry;
use App\Filament\Tenant\Support\SettingsTabRegistry;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Support\FiscalSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    App::setLocale('en');
});

test('bank accounts default tab is work queue', function () {
    request()->replace([]);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('queue');
});

test('bank accounts invalid tab falls back to work queue', function () {
    request()->replace(['tab' => 'invalid']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('queue');
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

test('settings page switches configuration tabs via livewire property', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Settings Tab Admin',
        'email' => 'settings-tab-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class)
        ->assertSet('settingsTab', 'general::tab')
        ->assertSee(__('Regional'))
        ->call('setSettingsTab', 'collection::tab')
        ->assertSet('settingsTab', 'collection::tab')
        ->assertSee(__('Contribution Cycle'))
        ->assertDontSee(__('Regional'))
        ->call('setSettingsTab', 'sms-templates::tab')
        ->assertSet('settingsTab', 'sms-templates::tab')
        ->assertSee(__('SMS import templates'))
        ->call('setSettingsTab', 'not-a-tab')
        ->assertSet('settingsTab', 'sms-templates::tab');
});

test('settings page falls back to general tab for invalid query tab', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Settings Fallback Admin',
        'email' => 'settings-fallback-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'invalid-tab'])
        ->assertSet('settingsTab', 'general::tab');
});

test('settings save from general tab preserves fiscal year settings', function () {
    Filament::setCurrentPanel('tenant');

    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 7,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    $admin = User::create([
        'name' => 'Settings Fiscal Preserve Admin',
        'email' => 'settings-fiscal-preserve@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'general::tab'])
        ->call('save')
        ->assertNotified();

    expect(FiscalSettings::fiscalYearStartMonth())->toBe(7)
        ->and(FiscalSettings::fiscalYearStartDay())->toBe(1)
        ->and(FiscalSettings::purgePolicy())->toBe(FiscalSettings::PURGE_RETAIN_7Y)
        ->and(FiscalSettings::currentFiscalYearLabel())->toBe('FY2026');
});

test('audit system tab registry exposes workspace tabs', function () {
    expect(AuditSystemTabRegistry::tabs())->toHaveKeys([
        'audit',
        'notifications',
        'jobs',
        'maintenance',
        'migration',
        'fiscal',
    ]);
});

test('audit system page switches workspace tabs via livewire property', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Audit Tab Admin',
        'email' => 'audit-tab-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->assertSet('sideTab', 'audit')
        ->assertSee(__('Fund audit log'))
        ->call('setSideTab', 'jobs')
        ->assertSet('sideTab', 'jobs')
        ->assertSee(__('Job catalog'))
        ->assertDontSee(__('Fund audit log'))
        ->call('setSideTab', 'maintenance')
        ->assertSet('sideTab', 'maintenance')
        ->assertSee(__('Database backups'))
        ->call('setSideTab', 'invalid')
        ->assertSet('sideTab', 'maintenance');
});

test('audit system page reconfigures table columns when switching between table tabs', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Audit Table Reconfig Admin',
        'email' => 'audit-table-reconfig@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->assertSee(__('Event'))
        ->call('setSideTab', 'notifications')
        ->assertSee(__('Recipient'))
        ->assertSee(__('Channel'))
        ->call('setSideTab', 'audit')
        ->assertSee(__('Event'))
        ->call('setSideTab', 'jobs')
        ->assertSee(__('Job catalog'))
        ->call('setJobsTab', 'history')
        ->assertSee(__('Run history'))
        ->call('setJobsTab', 'catalog')
        ->assertSee(__('Scheduled jobs'));
});

test('audit system admin can bulk delete audit and notification logs', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Audit Bulk Delete Admin',
        'email' => 'audit-bulk-delete@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $auditLog = FundAuditLog::create([
        'event_type' => 'TEST_BULK_DELETE',
        'domain' => 'ledger',
        'payload' => [],
        'checksum' => 'test-checksum',
        'occurred_at' => now(),
    ]);

    $notificationLog = NotificationLog::create([
        'user_id' => $admin->id,
        'channel' => 'mail',
        'subject' => 'Bulk delete test',
        'body' => 'Test body',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->callTableBulkAction('delete', [$auditLog])
        ->assertNotified();

    expect(FundAuditLog::query()->whereKey($auditLog->id)->exists())->toBeFalse();

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->call('setSideTab', 'notifications')
        ->callTableBulkAction('delete', [$notificationLog])
        ->assertNotified();

    $deletedNotification = NotificationLog::query()
        ->withTrashed()
        ->find($notificationLog->id);

    expect($deletedNotification)->not->toBeNull()
        ->and($deletedNotification->trashed())->toBeTrue();
});

test('audit system page falls back to audit tab for invalid query tab', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Audit Fallback Admin',
        'email' => 'audit-fallback-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'invalid-tab'])
        ->assertSet('sideTab', 'audit');
});

test('reconciliation tab registry exposes workspace tabs', function () {
    expect(ReconciliationTabRegistry::tabs())->toHaveKeys([
        'overview',
        'exceptions',
        'history',
        'snapshots',
        'methodology',
    ]);
});

test('reconciliation page switches workspace tabs via livewire method', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Reconciliation Tab Admin',
        'email' => 'reconciliation-tab-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ReconciliationOverviewPage::class)
        ->assertSet('sideTab', 'overview')
        ->assertSee(__('Open exceptions'))
        ->call('setSideTab', 'methodology')
        ->assertSet('sideTab', 'methodology')
        ->assertSee(__('Reconciliation approach'))
        ->assertDontSee(__('Open exceptions'))
        ->call('setSideTab', 'invalid')
        ->assertSet('sideTab', 'methodology');
});

test('reconciliation page falls back to overview tab for invalid query tab', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Reconciliation Fallback Admin',
        'email' => 'reconciliation-fallback-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ReconciliationOverviewPage::class, ['sideTab' => 'invalid-tab'])
        ->assertSet('sideTab', 'overview');
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
        ->assertSee(__('Legacy migration wizard'))
        ->assertSee(__('Step 1: Import members'));
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

    expect(array_keys($component->instance()->getAuditSystemTabs()))
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

    Setting::set('legacy_migration', 'members_imported', '1');
    Setting::set('legacy_migration', 'loans_imported', '1');

    Livewire::actingAs($admin, 'tenant')
        ->test(LegacyMigrationPage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Step 1: Import members'))
        ->assertDontSee(__('Step 2: Import loans'))
        ->call('goToStep', 2)
        ->assertSee(__('Step 2: Import loans'))
        ->call('goToStep', 3)
        ->assertSeeHtml('wire:click="mountAction(\'classifyPayments\'')
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
