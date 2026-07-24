<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyContributions\Pages\ListMyContributions;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Tenant\MemberRequestService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
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
    User::query()->where('email', 'like', '%open-cycle%')->delete();

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
        'name' => 'Open Cycle Admin',
        'email' => 'open-cycle-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Open Cycle Member',
        'email' => 'open-cycle-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'OC-001',
        'name' => 'Open Cycle Member',
        'email' => 'open-cycle-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    [$this->month, $this->year] = app(ContributionCycleService::class)->currentOpenPeriod();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('approve open-cycle contribution replaces amount_due and leaves standing allocation unchanged', function () {
    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 100000],
    );

    expect($request->type)->toBe(MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION)
        ->and((float) $request->payload['standing_amount'])->toBe(1000.0);

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect($contribution)->not->toBeNull()
        ->and((float) $contribution->amount_due)->toBe(100000.0)
        ->and((float) $contribution->amount)->toBe(100000.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(1000.0)
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED);
});

test('reject open-cycle contribution leaves cycle due unchanged', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate($this->month, $this->year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 50000],
    );

    app(MemberRequestService::class)->reject($request->fresh(), $this->admin, 'Not this cycle');

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect((float) $contribution->amount_due)->toBe(1000.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(1000.0)
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_REJECTED);
});

test('open-cycle amount must exceed standing allocation', function () {
    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 1000],
    ))->toThrow(ValidationException::class);
});

test('parent can request open-cycle amount for dependent', function () {
    $dependent = Member::create([
        'member_number' => 'OC-DEP-1',
        'name' => 'Open Cycle Dependent',
        'email' => 'open-cycle-dep@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'parent_member_id' => $this->member->id,
    ]);
    app(AccountingService::class)->createMemberAccounts($dependent);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 75000, 'target_member_id' => $dependent->id],
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($dependent->id, $this->month, $this->year);

    expect((float) $contribution->amount_due)->toBe(75000.0)
        ->and((float) $dependent->fresh()->monthly_contribution_amount)->toBe(500.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(1000.0);
});

test('sponsored dependent can request open-cycle amount for self', function () {
    $dependentUser = User::create([
        'name' => 'Dependent User',
        'email' => 'open-cycle-dep-user@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $dependent = Member::create([
        'user_id' => $dependentUser->id,
        'member_number' => 'OC-DEP-2',
        'name' => 'Self Request Dependent',
        'email' => 'open-cycle-dep-user@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'parent_member_id' => $this->member->id,
    ]);
    app(AccountingService::class)->createMemberAccounts($dependent);

    $request = app(MemberRequestService::class)->submit(
        $dependent,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 40000],
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect((float) Contribution::findForMemberPeriod($dependent->id, $this->month, $this->year)->amount_due)
        ->toBe(40000.0)
        ->and((float) $dependent->fresh()->monthly_contribution_amount)->toBe(500.0);
});

test('partially pending contribution can increase amount_due to at least amount collected', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate($this->month, $this->year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 400,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PARTIALLY_PENDING,
    ]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 25000],
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect((float) $contribution->amount_due)->toBe(25000.0)
        ->and((float) $contribution->amount_collected)->toBe(400.0)
        ->and($contribution->collection_status)->toBe(ContributionCollectionStatus::PARTIALLY_PENDING);
});

test('approved open-cycle amount is collected through the normal apply flow', function () {
    $accounting = app(AccountingService::class);
    $accounting->credit($this->member->fresh()->cashAccount, 120000, 'Seed cash');
    Account::masterCash()?->update(['balance' => 120000]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 100000],
    );
    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $results = ['applied' => [], 'insufficient' => [], 'skipped' => []];
    $outcome = app(ContributionService::class)->applyForPeriod(
        $this->member->fresh(),
        $this->month,
        $this->year,
        $results,
    );

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect($outcome)->toBe('applied')
        ->and($contribution->status)->toBe('posted')
        ->and((float) $contribution->amount_due)->toBe(100000.0)
        ->and((float) $contribution->amount_collected)->toBe(100000.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(1000.0);
});

test('duplicate pending open-cycle request for same member and period is rejected', function () {
    app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 20000],
    );

    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 30000],
    ))->toThrow(ValidationException::class);
});

test('member contributions list exposes request larger cycle amount header action', function () {
    app()->setLocale('en');
    Filament\Facades\Filament::setCurrentPanel('member');

    Livewire\Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyContributions::class)
        ->assertSuccessful()
        ->assertActionVisible('requestOpenCycleAmount');
});

test('open-cycle override does not break scheduled recon command exit codes', function () {
    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        ['amount' => 50000],
    );
    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect(Artisan::call('fund:assert-master-invariants', ['--force' => true]))->toBe(0)
        ->and(Artisan::call('fund:reconcile', ['--realtime' => true, '--no-store' => true]))->toBe(0);
});
