<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Late Clear Admin',
        'email' => 'late-clear-admin@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'MEM-CLP',
        'name' => 'Clear Late Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->lateContribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(1, 2025),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'posted',
        'is_late' => true,
        'posted_at' => Carbon::parse('2025-02-01'),
    ]);

    $this->onTimeContribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(2, 2025),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'posted',
        'is_late' => false,
        'posted_at' => Carbon::parse('2025-03-01'),
    ]);

    $this->actingAs($this->admin, 'tenant');
    Filament::setCurrentPanel('tenant');
});

test('member contributions table can clear late posting on a row', function () {
    Livewire::test(ContributionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->assertSuccessful()
        ->callTableAction('clear_late_posting', $this->lateContribution, data: ['note' => 'Approved'])
        ->assertNotified();

    expect($this->lateContribution->fresh()->is_late)->toBeFalse()
        ->and($this->onTimeContribution->fresh()->is_late)->toBeFalse();
});

test('member contributions table bulk clears late posting on selected rows', function () {
    $secondLate = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(3, 2025),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'posted',
        'is_late' => true,
        'posted_at' => Carbon::parse('2025-04-01'),
    ]);

    Livewire::test(ContributionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->callTableBulkAction('clearLatePostingSelected', [$this->lateContribution, $secondLate, $this->onTimeContribution])
        ->assertNotified();

    expect($this->lateContribution->fresh()->is_late)->toBeFalse()
        ->and($secondLate->fresh()->is_late)->toBeFalse()
        ->and($this->onTimeContribution->fresh()->is_late)->toBeFalse();
});
