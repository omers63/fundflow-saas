<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberStatusService;
use App\Services\Tenant\MemberRequestService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    User::query()->delete();
    Member::query()->delete();
    MemberRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Lifecycle Member',
        'email' => 'lifecycle@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-LIFE',
        'name' => 'Lifecycle Member',
        'email' => 'lifecycle@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->admin = User::create([
        'name' => 'Lifecycle Admin',
        'email' => 'lifecycle-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->requests = app(MemberRequestService::class);
    $this->statuses = app(MemberStatusService::class);
});

test('unfreeze request requires frozen inactive membership', function () {
    expect(fn () => $this->requests->submit(
        $this->member,
        MemberRequest::TYPE_UNFREEZE_MEMBERSHIP,
        ['reason' => 'Nope'],
    ))->toThrow(ValidationException::class);

    $this->statuses->suspendForGuarantorTransfer($this->member);

    expect(fn () => $this->requests->submit(
        $this->member->fresh(),
        MemberRequest::TYPE_UNFREEZE_MEMBERSHIP,
        ['reason' => 'Nope'],
    ))->toThrow(ValidationException::class);
});

test('admin can approve reinstate membership request', function () {
    $this->statuses->withdraw($this->member, 'Left');

    $request = $this->requests->submit(
        $this->member->fresh(),
        MemberRequest::TYPE_REINSTATE_MEMBERSHIP,
        ['reason' => 'Rejoin'],
    );

    $this->requests->approve($request->fresh(), $this->admin);

    expect($this->member->fresh()->status)->toBe('active')
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED);
});

test('admin can approve release payout request', function () {
    $this->statuses->terminate($this->member, 'Terminated');

    $request = $this->requests->submit(
        $this->member->fresh(),
        MemberRequest::TYPE_RELEASE_PAYOUT,
        ['reason' => 'Release funds'],
    );

    $this->requests->approve($request->fresh(), $this->admin);

    expect($this->member->fresh()->status)->toBe('withdrawn')
        ->and($this->member->fresh()->payout_frozen_at)->toBeNull()
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED);
});

test('login surface types match membership state', function () {
    expect(MemberRequest::loginSurfaceTypesFor($this->member))->toBe([]);

    $this->statuses->freeze($this->member, 'Pause');
    expect(MemberRequest::loginSurfaceTypesFor($this->member->fresh()))
        ->toBe([MemberRequest::TYPE_UNFREEZE_MEMBERSHIP]);

    $this->statuses->unfreeze($this->member->fresh());
    $this->statuses->withdraw($this->member->fresh(), 'Exit');
    expect(MemberRequest::loginSurfaceTypesFor($this->member->fresh()))
        ->toBe([MemberRequest::TYPE_REINSTATE_MEMBERSHIP]);

    $terminatedUser = User::create([
        'name' => 'Terminated Member',
        'email' => 'terminated-life@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $terminated = Member::create([
        'user_id' => $terminatedUser->id,
        'member_number' => 'MEM-TERM',
        'name' => 'Terminated Member',
        'email' => 'terminated-life@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($terminated);
    $this->statuses->terminate($terminated, 'Hold payout');

    expect(MemberRequest::loginSurfaceTypesFor($terminated->fresh()))->toBe([
        MemberRequest::TYPE_RELEASE_PAYOUT,
        MemberRequest::TYPE_REINSTATE_MEMBERSHIP,
    ]);
});
