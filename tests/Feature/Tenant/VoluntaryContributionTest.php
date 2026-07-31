<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Tenant\MemberRequestService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    $this->initializeTenancy();

    MemberRequest::query()->delete();
    Contribution::query()->delete();
    Account::query()->where('is_master', false)->delete();
    Member::query()->delete();
    User::query()->where('email', 'like', '%vol-contrib%')->delete();

    Account::query()->firstOrCreate(
        ['type' => 'cash', 'is_master' => true],
        ['name' => 'Master Cash', 'balance' => 0],
    );
    Account::query()->firstOrCreate(
        ['type' => 'fund', 'is_master' => true],
        ['name' => 'Master Fund', 'balance' => 0],
    );
    Account::query()->firstOrCreate(
        ['type' => 'fees', 'is_master' => true],
        ['name' => 'Master Fees', 'balance' => 0],
    );

    $this->admin = User::create([
        'name' => 'Vol Contrib Admin',
        'email' => 'vol-contrib-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Vol Contrib Member',
        'email' => 'vol-contrib-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'VC-001',
        'name' => 'Vol Contrib Member',
        'email' => 'vol-contrib-member@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    [$this->month, $this->year] = app(ContributionCycleService::class)->currentOpenPeriod();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('new voluntary contribution top-up requests are rejected', function () {
    $service = app(MemberRequestService::class);

    expect(fn () => $service->submit($this->member, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
        'amount' => 2500,
        'period_month' => $this->month,
        'period_year' => $this->year,
    ]))->toThrow(ValidationException::class);
});

test('pending legacy voluntary top-up can still be approved', function () {
    $request = MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => [
            'amount' => 3500,
            'period_month' => $this->month,
            'period_year' => $this->year,
            'target_member_id' => $this->member->id,
            'for_self' => true,
            'standing_amount' => 2000,
            'previous_amount_due' => 2000,
        ],
    ]);

    app(MemberRequestService::class)->approve($request, $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED)
        ->and($contribution)->not->toBeNull()
        ->and((float) $contribution->amount_due)->toBe(3500.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(2000.0)
        ->and($contribution->collection_status)->toBe(ContributionCollectionStatus::PENDING);
});

test('typeLabel for contribution top-up does not say voluntary', function () {
    $label = MemberRequest::typeLabel(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION);

    expect($label)->toBe(__('Contribution top-up'))
        ->and(strtolower($label))->not->toContain('voluntary');
});
