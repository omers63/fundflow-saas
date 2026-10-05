<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberSavingsGoal;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\Savings\MemberSavingsGoalService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    MemberSavingsGoal::query()->delete();
    Transaction::query()->delete();

    $this->user = User::create([
        'name' => 'Saver',
        'email' => 'saver@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-GOAL1',
        'name' => 'Saver',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'saver@test.com',
    ]);

    Account::create([
        'type' => 'fund',
        'name' => 'Member Fund',
        'balance' => 4000,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
});

test('savings goal progress uses live fund balance without ledger side effects', function () {
    $txnCountBefore = Transaction::query()->count();

    $goal = app(MemberSavingsGoalService::class)->create($this->member, [
        'title' => 'Emergency buffer',
        'target_amount' => 10000,
        'target_date' => now()->addMonths(8)->toDateString(),
    ]);

    $progress = app(MemberSavingsGoalService::class)->progress($goal);

    expect($progress['current_fund_balance'])->toBe(4000.0)
        ->and($progress['remaining'])->toBe(6000.0)
        ->and($progress['progress_percent'])->toBe(40.0)
        ->and($progress['months_to_goal'])->toBe(6)
        ->and(Transaction::query()->count())->toBe($txnCountBefore)
        ->and($goal->fresh()->status)->toBe(MemberSavingsGoal::STATUS_ACTIVE);
});

test('goal is marked achieved when fund balance meets target', function () {
    Account::query()->where('member_id', $this->member->id)->where('type', 'fund')->update(['balance' => 12000]);

    $goal = app(MemberSavingsGoalService::class)->create($this->member, [
        'title' => 'Done',
        'target_amount' => 10000,
    ]);

    expect($goal->status)->toBe(MemberSavingsGoal::STATUS_ACHIEVED)
        ->and($goal->achieved_at)->not->toBeNull();
});
