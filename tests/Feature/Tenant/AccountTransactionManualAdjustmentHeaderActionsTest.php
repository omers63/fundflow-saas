<?php

declare(strict_types=1);

use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
});

test('manual credit and debit header actions are visible for tenant admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-manual-txn-' . uniqid('', true) . '@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterCash()->create();
    $actions = AccountTransactionManualAdjustmentHeaderActions::make(fn() => $account);

    expect($actions)->toHaveCount(3)
        ->and($actions[0]->getName())->toBe('manualCredit')
        ->and($actions[1]->getName())->toBe('manualDebit')
        ->and($actions[2]->getName())->toBe('refundMemberCash')
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse()
        ->and($actions[2]->isHidden())->toBeTrue();
});

test('manual credit and debit header actions are hidden for non-admin tenant users', function () {
    $user = User::create([
        'name' => 'Member',
        'email' => 'member-manual-txn-' . uniqid('', true) . '@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $this->actingAs($user, 'tenant');

    $account = Account::factory()->masterCash()->create();
    $actions = AccountTransactionManualAdjustmentHeaderActions::make(fn() => $account);

    expect($actions[0]->isHidden())->toBeTrue()
        ->and($actions[1]->isHidden())->toBeTrue();
});

test('manual credit on a master account can tag a member without mirroring', function () {
    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Tagged Member',
            'email' => 'tagged-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-TAG-1',
        'name' => 'Tagged Member',
        'email' => 'tagged-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $masterCash = Account::factory()->masterCash()->withBalance(0)->create();
    $memberCash = Account::factory()->cash()->for($member)->withBalance(0)->create();

    AccountingService::withoutMemberCashCollection(fn() => app(AccountingService::class)->postManualCredit(
        $masterCash,
        75,
        'Tagged master credit',
        null,
        $member->id,
    ));

    $txn = $masterCash->transactions()->first();

    expect($txn->member_id)->toBe($member->id)
        ->and((float) $masterCash->fresh()->balance)->toBe(75.0)
        ->and((float) $memberCash->fresh()->balance)->toBe(0.0)
        ->and($masterCash->transactions()->count())->toBe(1);
});

test('manual credit on a member account uses the account member automatically without mirroring master cash', function () {
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Account Member',
            'email' => 'account-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-ACC-1',
        'name' => 'Account Member',
        'email' => 'account-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $otherMember = Member::create([
        'user_id' => User::create([
            'name' => 'Other Member',
            'email' => 'other-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-OTH-1',
        'name' => 'Other Member',
        'email' => 'other-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $memberCash = Account::factory()->cash()->for($member)->withBalance(0)->create();

    AccountingService::withoutMemberCashCollection(fn() => app(AccountingService::class)->postManualCredit(
        $memberCash,
        50,
        'Member cash credit',
        null,
        $otherMember->id,
    ));

    $masterCash = Account::masterCash();

    expect($memberCash->transactions()->first()->member_id)->toBe($member->id)
        ->and((float) $memberCash->fresh()->balance)->toBe(50.0)
        ->and((float) $masterCash->fresh()->balance)->toBe(0.0)
        ->and($memberCash->transactions()->count())->toBe(1);
});

test('manual credit posts a transaction on the account', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-manual-credit-' . uniqid('', true) . '@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterCash()->withBalance(100)->create();

    app(AccountingService::class)->postManualCredit($account, 25.5, 'Test manual credit', null);

    $account->refresh();
    expect((float) $account->balance)->toBe(125.5);

    $txn = $account->transactions()->latest('id')->first();
    expect($txn)->not->toBeNull()
        ->and($txn->type)->toBe('credit')
        ->and((float) $txn->amount)->toBe(25.5)
        ->and($txn->description)->toBe('Test manual credit');
});

test('manual credit can use a custom transaction datetime', function () {
    $transactedAt = Carbon::parse('2024-06-15 14:30:00');

    $account = Account::factory()->masterCash()->withBalance(0)->create();

    app(AccountingService::class)->postManualCredit(
        $account,
        10,
        'Backdated credit',
        $transactedAt,
    );

    $txn = $account->transactions()->first();

    expect($txn->transacted_at->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00');
});

test('refund header action is visible only on member cash accounts', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-refund-visible-' . uniqid('', true) . '@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    Account::factory()->masterCash()->create();

    $memberCash = Account::factory()->cash()->create();
    $memberFund = Account::factory()->fund()->create();

    $cashActions = AccountTransactionManualAdjustmentHeaderActions::make(fn() => $memberCash);
    $fundActions = AccountTransactionManualAdjustmentHeaderActions::make(fn() => $memberFund);
    $masterActions = AccountTransactionManualAdjustmentHeaderActions::make(fn() => Account::masterCash());

    expect($cashActions)->toHaveCount(3)
        ->and(collect($cashActions)->map->getName()->all())->toContain('refundMemberCash')
        ->and($cashActions[2]->isHidden())->toBeFalse()
        ->and(collect($fundActions)->first(fn($action) => $action->getName() === 'refundMemberCash')->isHidden())->toBeTrue()
        ->and(collect($masterActions)->first(fn($action) => $action->getName() === 'refundMemberCash')->isHidden())->toBeTrue();
});

test('refund debits member cash and master cash', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 10_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Refund Member',
            'email' => 'refund-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-REF-1',
        'name' => 'Refund Member',
        'email' => 'refund-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $memberCash = Account::factory()->cash()->for($member)->withBalance(500)->create();

    app(AccountingService::class)->refundMemberCash($memberCash, 150, 'Member withdrawal');

    $memberCash->refresh();
    $masterCash = Account::masterCash();

    expect((float) $memberCash->balance)->toBe(350.0)
        ->and((float) $masterCash->balance)->toBe(9850.0)
        ->and($memberCash->transactions()->where('type', 'debit')->count())->toBe(1)
        ->and($masterCash->transactions()->where('type', 'debit')->count())->toBe(1);
});

test('manual debit on member fund may exceed balance and go negative', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Fund Debit Member',
            'email' => 'fund-debit-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-FND-DEB',
        'name' => 'Fund Debit Member',
        'email' => 'fund-debit-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $memberFund = Account::factory()->fund()->for($member)->withBalance(53_000)->create();

    app(AccountingService::class)->postManualDebit($memberFund, 80_000, 'Loan allocation adjustment');

    expect((float) $memberFund->fresh()->balance)->toBe(-27_000.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(0.0)
        ->and($memberFund->transactions()->where('type', 'debit')->count())->toBe(1);
});

test('manual debit on member cash still requires sufficient balance', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);

    $memberCash = Account::factory()->cash()->withBalance(100)->create();

    app(AccountingService::class)->postManualDebit($memberCash, 150, 'Too much');
})->throws(InvalidArgumentException::class);

test('manual credit on member fund only affects member fund', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Fund Member',
            'email' => 'fund-member-' . uniqid('', true) . '@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-FND-1',
        'name' => 'Fund Member',
        'email' => 'fund-member-' . uniqid('', true) . '@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $memberFund = Account::factory()->fund()->for($member)->withBalance(0)->create();

    app(AccountingService::class)->postManualCredit($memberFund, 120, 'Fund adjustment');

    expect((float) $memberFund->fresh()->balance)->toBe(120.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(0.0)
        ->and($memberFund->transactions()->count())->toBe(1);
});

test('manual credit on master expense only affects expense account', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    $expense = Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->postManualCredit($expense, 40, 'Expense top-up');

    expect((float) $expense->fresh()->balance)->toBe(40.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(500.0)
        ->and($expense->transactions()->count())->toBe(1);
});

test('manual credit on master invest only affects invest account', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 300, 'is_master' => true]);
    $invest = Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->postManualCredit($invest, 90, 'Invest top-up');

    expect((float) $invest->fresh()->balance)->toBe(90.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(300.0)
        ->and($invest->transactions()->count())->toBe(1);
});

test('manual debit on master fees only affects fees account', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100, 'is_master' => true]);
    $fees = Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 30, 'is_master' => true]);

    app(AccountingService::class)->postManualDebit($fees, 30, 'Fees reversal');

    expect((float) $fees->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(100.0)
        ->and($fees->transactions()->count())->toBe(1);
});

test('refund rejects amounts above member cash balance', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);

    $memberCash = Account::factory()->cash()->withBalance(50)->create();

    app(AccountingService::class)->refundMemberCash($memberCash, 100, 'Too much');
})->throws(InvalidArgumentException::class);
