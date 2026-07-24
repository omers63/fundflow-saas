<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\PortalAccessLog;
use App\Models\Tenant\User;
use App\Services\Tenant\FundAuditLogExportService;
use App\Services\Tenant\NotificationLogExportService;
use App\Services\Tenant\PortalAccessLogExportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('fund audit log export streams csv with headers and rows', function () {
    FundAuditLog::create([
        'event_type' => 'EXPORT_TEST',
        'domain' => 'ledger',
        'payload' => ['ok' => true],
        'checksum' => 'checksum',
        'occurred_at' => now(),
    ]);

    $response = app(FundAuditLogExportService::class)->downloadCsv();
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('event_type')
        ->and($csv)->toContain('EXPORT_TEST');
});

test('notification and portal access log exports stream csv rows', function () {
    $user = User::create([
        'name' => 'Export User',
        'email' => 'export-user@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    NotificationLog::create([
        'user_id' => $user->id,
        'channel' => 'mail',
        'subject' => 'Hello export',
        'body' => 'Body',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    PortalAccessLog::create([
        'user_id' => $user->id,
        'member_name' => 'Export Member',
        'panel' => PortalAccessLog::PANEL_MEMBER,
        'ip_address' => '127.0.0.1',
        'accessed_at' => now(),
    ]);

    $notificationResponse = app(NotificationLogExportService::class)->downloadCsv();
    ob_start();
    $notificationResponse->sendContent();
    $notificationCsv = (string) ob_get_clean();

    $accessResponse = app(PortalAccessLogExportService::class)->downloadCsv();
    ob_start();
    $accessResponse->sendContent();
    $accessCsv = (string) ob_get_clean();

    expect($notificationCsv)->toContain('Hello export')
        ->and($notificationCsv)->toContain('recipient_email')
        ->and($accessCsv)->toContain('Export Member')
        ->and($accessCsv)->toContain('member_name');
});

test('audit system page exposes export csv next to empty log actions', function () {
    $admin = User::create([
        'name' => 'Log Export Admin',
        'email' => 'log-export-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->assertSee(__('Export CSV'))
        ->assertSee(__('Empty audit log table'))
        ->call('setSideTab', 'access')
        ->assertSee(__('Export CSV'))
        ->assertSee(__('Empty access log table'))
        ->call('setSideTab', 'notifications')
        ->assertSee(__('Export CSV'))
        ->assertSee(__('Empty notification log table'));
});

test('non-admin cannot export system logs from audit system page', function () {
    $user = User::create([
        'name' => 'Log Export Staff',
        'email' => 'log-export-staff@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($user, 'tenant');

    Livewire::test(AuditSystemPage::class)
        ->call('exportFundAuditLogs')
        ->assertForbidden();
});
