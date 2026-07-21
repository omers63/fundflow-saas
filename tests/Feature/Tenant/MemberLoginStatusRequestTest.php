<?php

declare(strict_types=1);

use App\Livewire\Tenant\MemberLoginPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberStatusService;
use App\Services\Tenant\MemberRequestService;
use Livewire\Livewire;
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
        'name' => 'Blocked Member',
        'email' => 'blocked@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-BLOCK',
        'name' => 'Blocked Member',
        'email' => 'blocked@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->admin = User::create([
        'name' => 'Status Admin',
        'email' => 'status-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('frozen member can request unfreeze from login without portal session', function () {
    app(MemberStatusService::class)->freeze($this->member, 'Travel');

    expect($this->member->fresh()->status)->toBe('inactive')
        ->and($this->member->fresh()->frozen_at)->not->toBeNull();

    $component = Livewire::test(MemberLoginPage::class)
        ->set('email', 'blocked@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSet('statusType', 'inactive')
        ->assertSet('showStatusRequestForm', true)
        ->assertSet('statusRequestType', MemberRequest::TYPE_UNFREEZE_MEMBERSHIP)
        ->assertNoRedirect();

    expect(auth('tenant')->check())->toBeFalse();

    $component
        ->set('statusRequestReason', 'Back from travel')
        ->call('submitStatusRequest')
        ->assertSet('statusRequestSuccess', __('Request submitted. Fund administration will review it shortly.'));

    $request = MemberRequest::query()->first();

    expect($request)->not->toBeNull()
        ->and($request->type)->toBe(MemberRequest::TYPE_UNFREEZE_MEMBERSHIP)
        ->and($request->status)->toBe(MemberRequest::STATUS_PENDING)
        ->and($request->requester_member_id)->toBe($this->member->id);

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect($this->member->fresh()->status)->toBe('active')
        ->and($this->member->fresh()->frozen_at)->toBeNull();
});

test('withdrawn member can request reinstate from login and regain active status on approve', function () {
    app(MemberStatusService::class)->withdraw($this->member, 'Moving away');

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'blocked@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSet('statusType', 'withdrawn')
        ->assertSet('showStatusRequestForm', true)
        ->set('statusRequestReason', 'Want to rejoin')
        ->call('submitStatusRequest')
        ->assertSet('statusRequestSuccess', __('Request submitted. Fund administration will review it shortly.'));

    $request = MemberRequest::query()->where('type', MemberRequest::TYPE_REINSTATE_MEMBERSHIP)->first();

    expect($request)->not->toBeNull();

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect($this->member->fresh()->status)->toBe('active');
});

test('terminated member can request release payout from login', function () {
    app(MemberStatusService::class)->terminate($this->member, 'Policy breach');

    expect($this->member->fresh()->payout_frozen_at)->not->toBeNull();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'blocked@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSet('statusType', 'terminated')
        ->assertSet('showStatusRequestForm', true)
        ->set('statusRequestType', MemberRequest::TYPE_RELEASE_PAYOUT)
        ->set('statusRequestReason', 'Need settlement funds')
        ->call('submitStatusRequest')
        ->assertSet('statusRequestSuccess', __('Request submitted. Fund administration will review it shortly.'));

    $request = MemberRequest::query()->where('type', MemberRequest::TYPE_RELEASE_PAYOUT)->first();

    expect($request)->not->toBeNull();

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect($this->member->fresh()->status)->toBe('withdrawn')
        ->and($this->member->fresh()->payout_frozen_at)->toBeNull();
});

test('non-frozen inactive member does not get login status request form', function () {
    app(MemberStatusService::class)->suspendForGuarantorTransfer($this->member);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'blocked@fund.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSet('statusType', 'suspended')
        ->assertSet('showStatusRequestForm', false);

    expect(MemberRequest::query()->count())->toBe(0);
});
