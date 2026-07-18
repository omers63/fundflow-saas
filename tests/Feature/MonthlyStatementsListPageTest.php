<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\ListMonthlyStatements;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\AccountingService;
use App\Services\MonthlyStatementInsightsService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    MonthlyStatement::query()->forceDelete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Statements Admin',
        'email' => 'statements-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Statement Member',
        'email' => 'statement-member-page@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-STMT-PAGE-01',
        'name' => 'Statement Member',
        'email' => 'statement-member-page@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    Filament::setCurrentPanel('tenant');
});

test('monthly statements list exposes grouped page actions and bulk toolbar actions', function () {
    MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => now()->subMonthNoOverflow()->format('Y-m'),
        'opening_balance' => 100,
        'total_contributions' => 50,
        'total_repayments' => 25,
        'closing_balance' => 125,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMonthlyStatements::class)
        ->assertSuccessful()
        ->assertSee(__('New'))
        ->assertSee(__('Generate'))
        ->assertSee(__('Deliver'))
        ->assertSee(__('Unsent'))
        ->assertActionExists('generate_previous')
        ->assertActionExists('notify_unsent')
        ->assertTableActionExists('notify')
        ->assertTableActionExists('email')
        ->assertTableActionExists('regenerate')
        ->assertTableBulkActionExists('notifySelected')
        ->assertTableBulkActionExists('emailSelected')
        ->assertTableBulkActionExists('regenerateSelected')
        ->assertTableBulkActionExists('delete')
        ->assertTableBulkActionDoesNotExist('generate');
});

test('insights filter urls and period notify rate stay in sync with table filters', function () {
    $period = now()->subMonthNoOverflow()->format('Y-m');

    MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => $period,
        'opening_balance' => 0,
        'total_contributions' => 1000,
        'total_repayments' => 0,
        'closing_balance' => 1000,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    $other = Member::create([
        'user_id' => User::create([
            'name' => 'Other',
            'email' => 'other-stmt@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-STMT-PAGE-02',
        'name' => 'Other Member',
        'email' => 'other-stmt@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    MonthlyStatement::create([
        'member_id' => $other->id,
        'period' => $period,
        'opening_balance' => 0,
        'total_contributions' => 500,
        'total_repayments' => 0,
        'closing_balance' => 500,
        'generated_at' => now(),
        'notified_at' => now(),
    ]);

    $snapshot = app(MonthlyStatementInsightsService::class)->snapshot();

    expect($snapshot['pending_notify'])->toBe(1)
        ->and($snapshot['notified'])->toBe(1)
        ->and($snapshot['latest_period']['notify_rate'])->toBe(50)
        ->and($snapshot['filter_urls']['unsent'])->toContain('tableFilters')
        ->and($snapshot['filter_urls']['unsent'])->toContain('notified')
        ->and($snapshot['filter_urls']['latest_period'])->toContain($period)
        ->and(MonthlyStatementResource::getNavigationBadge())->toBe('1');
});

test('bulk notify marks selected unsent statements as sent', function () {
    Notification::fake();

    $statement = MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-05',
        'opening_balance' => 0,
        'total_contributions' => 100,
        'total_repayments' => 0,
        'closing_balance' => 100,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMonthlyStatements::class)
        ->callTableBulkAction('notifySelected', [$statement])
        ->assertHasNoTableBulkActionErrors();

    expect($statement->fresh()->notified_at)->not->toBeNull();

    Notification::assertSentTo($this->memberUser, MonthlyStatementNotification::class);
});

test('bulk regenerate recalculates selected statements and clears delivery', function () {
    $statement = MonthlyStatement::create([
        'member_id' => $this->member->id,
        'period' => '2026-04',
        'opening_balance' => 10,
        'total_contributions' => 20,
        'total_repayments' => 5,
        'closing_balance' => 25,
        'generated_at' => now()->subDay(),
        'notified_at' => now()->subDay(),
        'details' => [],
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMonthlyStatements::class)
        ->callTableBulkAction('regenerateSelected', [$statement])
        ->assertHasNoTableBulkActionErrors();

    $fresh = $statement->fresh();

    expect($fresh->notified_at)->toBeNull()
        ->and($fresh->generated_at->greaterThan($statement->generated_at))->toBeTrue();
});
