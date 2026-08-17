<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundOutRequestAcceptedNotification;
use App\Notifications\Tenant\FundOutRequestRejectedNotification;
use App\Notifications\Tenant\NewFundOutRequestNotification;
use App\Services\AccountingService;
use App\Services\MemberFreezeService;
use App\Services\MemberFundOutService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    FundOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Fund Out Admin',
        'email' => 'admin-fundout@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Fund Out Member',
        'email' => 'member-fundout@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-FO1',
        'name' => 'Fund Out Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->member);
    $this->service = app(MemberFundOutService::class);

    $this->accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        5000,
        'Seed fund',
        __('(master fund mirror)'),
        $this->member,
    );

    $this->member->refresh();
});

test('fund out submit notifies admins and reduces available fund', function () {
    $request = $this->service->submit($this->member, 1500, 'Need cash');

    expect($request->status)->toBe('pending')
        ->and((float) $request->amount)->toBe(1500.0)
        ->and($this->service->availableFundForTransfer($this->member->fresh()))->toBe(3500.0);

    Notification::assertSentTo($this->admin, NewFundOutRequestNotification::class);
});

test('fund out accept moves fund to cash with master mirrors', function () {
    $request = $this->service->submit($this->member, 2000);

    $fundBefore = (float) $this->member->fresh()->fundAccount->balance;
    $cashBefore = (float) $this->member->fresh()->cashAccount->balance;
    $masterFundBefore = (float) Account::masterFund()->balance;
    $masterCashBefore = (float) Account::masterCash()->balance;

    $this->service->accept($request->fresh(), $this->admin->id, 'ok');

    $this->member->unsetRelation('fundAccount');
    $this->member->unsetRelation('cashAccount');
    $this->member->refresh();
    $request->refresh();

    expect($request->status)->toBe('accepted')
        ->and((float) Account::query()->find($this->member->fundAccount->id)->balance)->toBe($fundBefore - 2000)
        ->and((float) Account::query()->find($this->member->cashAccount->id)->balance)->toBe($cashBefore + 2000)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe($masterFundBefore - 2000)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe($masterCashBefore + 2000);

    Notification::assertSentTo($this->memberUser, FundOutRequestAcceptedNotification::class);
});

test('fund out reject requires remarks and does not move balances', function () {
    $request = $this->service->submit($this->member, 500);
    $fundBefore = (float) $this->member->fresh()->fundAccount->balance;

    expect(fn () => $this->service->reject($request->fresh(), $this->admin->id, null))
        ->toThrow(InvalidArgumentException::class);

    $this->service->reject($request->fresh(), $this->admin->id, 'Incomplete notes');

    expect($request->fresh()->status)->toBe('rejected')
        ->and((float) $this->member->fresh()->fundAccount->balance)->toBe($fundBefore);

    Notification::assertSentTo($this->memberUser, FundOutRequestRejectedNotification::class);
});

test('member can cancel a pending fund-out and restore available fund', function () {
    $request = $this->service->submit($this->member, 1500);

    expect($this->service->availableFundForTransfer($this->member->fresh()))->toBe(3500.0);

    $this->service->cancel($request, $this->memberUser->id);

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($this->service->availableFundForTransfer($this->member->fresh()))->toBe(5000.0)
        ->and((float) $this->member->fresh()->fundAccount->balance)->toBe(5000.0);

    $reasons = app(MemberFreezeService::class)->blockingReasons($this->member->fresh());
    expect(collect($reasons)->implode(' '))->not->toContain('fund-out');
});

test('frozen members cannot submit fund outs', function () {
    app(MemberFreezeService::class)->applyFreeze($this->member, [
        'cycles' => 1,
        'reason' => 'travel',
    ]);

    expect(fn () => $this->service->submit($this->member->fresh(), 100))
        ->toThrow(InvalidArgumentException::class);
});

test('pending fund out blocks freeze', function () {
    $this->service->submit($this->member, 100);

    $reasons = app(MemberFreezeService::class)->blockingReasons($this->member);

    expect($reasons)->not->toBeEmpty()
        ->and(collect($reasons)->implode(' '))->toContain(__('fund-out requests'));

    expect(fn () => app(MemberFreezeService::class)->applyFreeze($this->member, [
        'cycles' => 1,
        'reason' => 'blocked',
    ]))->toThrow(ValidationException::class);
});
