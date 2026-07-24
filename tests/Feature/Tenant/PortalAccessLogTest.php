<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\Member;
use App\Models\Tenant\PortalAccessLog;
use App\Models\Tenant\User;
use App\Services\PortalAccessLogService;
use App\Support\SystemLoggingSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    PortalAccessLog::query()->withTrashed()->forceDelete();
    SystemLoggingSettings::setPortalAccessLogEnabled(true);
});

test('portal access log records member name on member portal sign-in', function () {
    $user = User::create([
        'name' => 'Access User',
        'email' => 'access-user@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-ACCESS',
        'name' => 'Access Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $log = app(PortalAccessLogService::class)->record(
        $user,
        PortalAccessLog::PANEL_MEMBER,
        $member,
    );

    expect($log)->not->toBeNull()
        ->and($log->member_name)->toBe('Access Member')
        ->and($log->panel)->toBe(PortalAccessLog::PANEL_MEMBER)
        ->and(PortalAccessLog::query()->count())->toBe(1);
});

test('portal access logging can be disabled', function () {
    SystemLoggingSettings::setPortalAccessLogEnabled(false);

    $user = User::create([
        'name' => 'Silent User',
        'email' => 'silent-user@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $log = app(PortalAccessLogService::class)->record(
        $user,
        PortalAccessLog::PANEL_ADMIN,
    );

    expect($log)->toBeNull()
        ->and(PortalAccessLog::query()->count())->toBe(0);
});

test('audit system access tab lists member names', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Access Admin',
        'email' => 'access-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    PortalAccessLog::create([
        'user_id' => $admin->id,
        'member_id' => null,
        'member_name' => 'Shown Member',
        'panel' => PortalAccessLog::PANEL_MEMBER,
        'ip_address' => '127.0.0.1',
        'accessed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->call('setSideTab', 'access')
        ->assertSet('sideTab', 'access')
        ->assertSee(__('Portal access log'))
        ->assertSee('Shown Member');
});
