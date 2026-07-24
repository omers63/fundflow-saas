<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\AccountingService;
use App\Services\FundAuditLogService;
use App\Services\SystemLogMaintenanceService;
use App\Support\SystemLoggingSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    FundAuditLog::query()->delete();
    NotificationLog::query()->withTrashed()->forceDelete();

    SystemLoggingSettings::setFundAuditLogEnabled(true);
    SystemLoggingSettings::setNotificationLogEnabled(true);
});

test('system logging defaults when settings are not stored', function () {
    Setting::query()->where('group', SystemLoggingSettings::GROUP)->delete();

    expect(SystemLoggingSettings::fundAuditLogEnabled())->toBeFalse()
        ->and(SystemLoggingSettings::notificationLogEnabled())->toBeFalse()
        ->and(SystemLoggingSettings::portalAccessLogEnabled())->toBeTrue();
});

test('fund audit log service skips persistence when audit logging is disabled', function () {
    SystemLoggingSettings::setFundAuditLogEnabled(false);

    $log = app(FundAuditLogService::class)->log('SKIPPED_EVENT', 'test', payload: ['x' => 1]);

    expect($log)->toBeNull()
        ->and(FundAuditLog::query()->count())->toBe(0);
});

test('system log maintenance service truncates audit and notification tables', function () {
    FundAuditLog::create([
        'event_type' => 'TRUNCATE_TEST',
        'domain' => 'ledger',
        'payload' => [],
        'checksum' => 'checksum',
        'occurred_at' => now(),
    ]);

    $user = User::create([
        'name' => 'Log User',
        'email' => 'log-user@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    NotificationLog::create([
        'user_id' => $user->id,
        'channel' => 'mail',
        'subject' => 'Test',
        'body' => 'Body',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $service = app(SystemLogMaintenanceService::class);

    expect($service->fundAuditLogRowCount())->toBe(1)
        ->and($service->notificationLogRowCount())->toBe(1)
        ->and($service->truncateFundAuditLogs())->toBe(1)
        ->and($service->truncateNotificationLogs())->toBe(1)
        ->and($service->fundAuditLogRowCount())->toBe(0)
        ->and($service->notificationLogRowCount())->toBe(0);
});

test('audit system admin can toggle logging and truncate tables', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Logging Admin',
        'email' => 'logging-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    FundAuditLog::create([
        'event_type' => 'UI_TRUNCATE_TEST',
        'domain' => 'ledger',
        'payload' => [],
        'checksum' => 'checksum',
        'occurred_at' => now(),
    ]);

    NotificationLog::create([
        'user_id' => $admin->id,
        'channel' => 'database',
        'subject' => 'Notify',
        'body' => 'Body',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->set('auditLoggingEnabled', false)
        ->assertNotified()
        ->call('truncateFundAuditLogs')
        ->assertNotified();

    expect(SystemLoggingSettings::fundAuditLogEnabled())->toBeFalse()
        ->and(FundAuditLog::query()->count())->toBe(0);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->call('setSideTab', 'notifications')
        ->set('notificationLoggingEnabled', false)
        ->assertNotified()
        ->call('truncateNotificationLogs')
        ->assertNotified();

    expect(SystemLoggingSettings::notificationLogEnabled())->toBeFalse()
        ->and(NotificationLog::query()->withTrashed()->count())->toBe(0);
});

test('notification delivery listener skips persistence when notification logging is disabled', function () {
    SystemLoggingSettings::setNotificationLogEnabled(false);

    $user = User::create([
        'name' => 'Notify User',
        'email' => 'notify-user@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-NOLOG',
        'name' => 'Notify Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $statement = MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => '2026-05',
        'opening_balance' => 100,
        'total_contributions' => 50,
        'total_repayments' => 25,
        'closing_balance' => 125,
        'generated_at' => now(),
    ]);

    $user->notify(new MonthlyStatementNotification($statement));

    expect(NotificationLog::query()->count())->toBe(0);
});
