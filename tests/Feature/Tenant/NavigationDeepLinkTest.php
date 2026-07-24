<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\CommunicationsWorkspacePage;
use App\Filament\Tenant\Pages\FiscalYearClosePage;
use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Filament\Tenant\Pages\SystemMaintenancePage;
use App\Filament\Tenant\Resources\FundAuditLogs\Pages\ListFundAuditLogs;
use App\Filament\Tenant\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Tenant\Support\CommunicationsTabRegistry;
use App\Filament\Tenant\Support\SettingsTabRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->admin = User::create([
        'name' => 'Nav Admin',
        'email' => 'nav-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('communications settings tab deep-links into settings communication tab', function () {
    expect(CommunicationsTabRegistry::url(CommunicationsTabRegistry::TAB_SETTINGS))
        ->toContain('settingsTab=communication%3A%3Atab')
        ->and(SettingsTabRegistry::url('communication::tab'))
        ->toContain('settingsTab=communication%3A%3Atab');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'settings'])
        ->assertRedirect(SettingsTabRegistry::url('communication::tab'));
});

test('legacy audit workspace routes redirect into audit and system tabs', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(SystemMaintenancePage::class)
        ->assertRedirect(AuditSystemPage::getUrl(['sideTab' => 'maintenance']));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->assertRedirect(AuditSystemPage::getUrl(['sideTab' => 'migration']));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(FiscalYearClosePage::class)
        ->assertRedirect(AuditSystemPage::getUrl(['sideTab' => 'fiscal']));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListFundAuditLogs::class)
        ->assertRedirect(AuditSystemPage::getUrl(['sideTab' => 'audit']));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListNotificationLogs::class)
        ->assertRedirect(AuditSystemPage::getUrl(['sideTab' => 'notifications']));
});

test('member contribution settings deep link uses member settings contributions tab', function () {
    Filament::setCurrentPanel('member');

    expect(MemberSettingsPage::getUrl(['tab' => 'contributions'], panel: 'member'))
        ->toContain('settings')
        ->toContain('tab=contributions');
});
