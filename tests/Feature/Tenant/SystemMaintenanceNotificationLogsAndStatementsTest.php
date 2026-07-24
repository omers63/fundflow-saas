<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\SystemMaintenancePage;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\ListMonthlyStatements;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\AccountingService;
use App\Support\SystemLoggingSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    NotificationLog::query()->forceDelete();
    MonthlyStatement::query()->forceDelete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'System Admin',
        'email' => 'system-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Statement Member',
        'email' => 'statement-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-STMT-01',
        'name' => 'Statement Member',
        'email' => 'statement-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    SystemLoggingSettings::setNotificationLogEnabled(true);
});

test('tenant admin can access system maintenance and notification log pages', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(SystemMaintenancePage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Database backups'))
        ->assertDontSee(__('Purge database (destructive)'));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(SystemMaintenancePage::class, ['embedded' => true])
        ->call('setAdvancedUi', true)
        ->assertSee(__('Purge database (destructive)'))
        ->assertSee(__('Tables that will be emptied'))
        ->assertSeeHtml('ff-maintenance-danger-banner')
        ->assertSeeHtml('ff-maintenance-scroll');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => 'notifications'])
        ->assertSuccessful()
        ->assertSee(__('Notification delivery log'));
});

test('notification delivery is logged when a notification is sent', function () {
    $statement = MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-05',
        'opening_balance' => 100,
        'total_contributions' => 50,
        'total_repayments' => 25,
        'closing_balance' => 125,
        'generated_at' => now(),
    ]);

    $this->memberUser->notify(new MonthlyStatementNotification($statement));

    expect(NotificationLog::query()->count())->toBeGreaterThan(0)
        ->and(NotificationLog::query()->where('user_id', $this->memberUser->id)->exists())->toBeTrue();
});

test('monthly statements list shows opening balance and un-notified badge', function () {
    MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-05',
        'opening_balance' => 500,
        'total_contributions' => 100,
        'total_repayments' => 50,
        'closing_balance' => 550,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMonthlyStatements::class)
        ->assertSuccessful()
        ->assertSee('MEM-STMT-01')
        ->assertSee('2026-05');

    expect(MonthlyStatementResource::getNavigationBadge())
        ->toBe('1');
});
