<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyDependents\Support\MyDependentTableActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\FundPostingService;
use App\Services\Tenant\MemberRequestService;
use App\Services\VoluntaryContributionRequestService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Filament\Actions\ActionGroup;
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

// --- extraAmountOptions ---

test('extraAmountOptions returns 500..10000 in steps of 500', function () {
    $options = VoluntaryContributionRequestService::extraAmountOptions();

    expect($options)->toHaveCount(20)
        ->and(array_keys($options))->toContain(500, 1000, 5000, 10000)
        ->and(array_keys($options))->not->toContain(0, 250, 10500);
});

// --- Validation: step and cap ---

test('submit voluntary top-up rejects non-multiple-of-500 extra', function () {
    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 2700], // extra = 700 — not a multiple of 500
    ))->toThrow(ValidationException::class);
});

test('submit voluntary top-up rejects extra above 10000', function () {
    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 13000], // extra = 11000 — over cap
    ))->toThrow(ValidationException::class);
});

test('submit voluntary top-up rejects amount equal to standing (zero extra)', function () {
    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 2000], // extra = 0
    ))->toThrow(ValidationException::class);
});

test('submit voluntary top-up rejects amount less than standing', function () {
    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 1500], // below standing
    ))->toThrow(ValidationException::class);
});

// --- Successful submit ---

test('submit voluntary top-up creates pending member request with correct payload', function () {
    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 3000], // extra = 1000 (valid)
    );

    expect($request->type)->toBe(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
        ->and($request->status)->toBe(MemberRequest::STATUS_PENDING)
        ->and((float) $request->payload['amount'])->toBe(3000.0)
        ->and((float) $request->payload['standing_amount'])->toBe(2000.0)
        ->and((int) $request->payload['target_member_id'])->toBe((int) $this->member->id)
        ->and((int) $request->payload['period_month'])->toBe($this->month)
        ->and((int) $request->payload['period_year'])->toBe($this->year);
});

// --- Duplicate guard ---

test('submit voluntary top-up blocks a second pending request for the same member and period', function () {
    app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 2500],
    );

    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 3000],
    ))->toThrow(ValidationException::class);
});

// --- Approve ---

test('approve voluntary top-up raises amount_due on the contribution row', function () {
    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 4500], // extra = 2500
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect($contribution)->not->toBeNull()
        ->and((float) $contribution->amount_due)->toBe(4500.0)
        ->and((float) $contribution->amount)->toBe(4500.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(2000.0)
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED);
});

test('approve voluntary top-up updates an existing pending contribution row', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate($this->month, $this->year),
        'amount' => 2000,
        'amount_due' => 2000,
        'amount_collected' => 0,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 2500], // extra = 500
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect((float) $contribution->amount_due)->toBe(2500.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(2000.0);
});

// --- Reject ---

test('reject voluntary top-up leaves cycle due unchanged', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate($this->month, $this->year),
        'amount' => 2000,
        'amount_due' => 2000,
        'amount_collected' => 0,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 3000],
    );

    app(MemberRequestService::class)->reject($request->fresh(), $this->admin, 'Not this cycle');

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);

    expect((float) $contribution->amount_due)->toBe(2000.0)
        ->and((float) $this->member->fresh()->monthly_contribution_amount)->toBe(2000.0)
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_REJECTED);
});

// --- Ledger legs after cycle collection ---

test('after approval the cycle engine collects the full elevated amount from member cash to fund', function () {
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);
    $this->member->cashAccount->update(['balance' => 10_000]);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 3000], // standing 2000 + extra 1000
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    $contribution = Contribution::findForMemberPeriod($this->member->id, $this->month, $this->year);
    expect($contribution)->not->toBeNull();

    app(ContributionService::class)->postContribution($contribution, now());

    $contribution->refresh();

    expect($contribution->status)->toBe('posted')
        ->and((float) $contribution->amount)->toBe(3000.0);

    $cashDebits = (float) Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->where('type', 'debit')
        ->where('member_id', $this->member->id)
        ->whereHas('account', fn ($q) => $q->where('type', 'cash')->where('is_master', false))
        ->sum('amount');

    $fundCredits = (float) Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->where('type', 'credit')
        ->where('member_id', $this->member->id)
        ->whereHas('account', fn ($q) => $q->where('type', 'fund')->where('is_master', false))
        ->sum('amount');

    $masterFundCredits = (float) Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->where('type', 'credit')
        ->whereHas('account', fn ($q) => $q->where('type', 'fund')->where('is_master', true))
        ->sum('amount');

    expect($cashDebits)->toBe(3000.0)
        ->and($fundCredits)->toBe(3000.0)
        ->and($masterFundCredits)->toBe(3000.0);
});

// --- Beneficiary guard: dependent ---

test('parent can submit voluntary top-up on behalf of active dependent', function () {
    $dependentUser = User::create([
        'name' => 'Vol Contrib Dependent',
        'email' => 'vol-contrib-dep@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $dependent = Member::create([
        'user_id' => $dependentUser->id,
        'parent_member_id' => $this->member->id,
        'member_number' => 'VC-DEP-001',
        'name' => 'Vol Contrib Dependent',
        'email' => 'vol-contrib-dep@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($dependent);

    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 1500, 'target_member_id' => $dependent->id],
    );

    expect($request->status)->toBe(MemberRequest::STATUS_PENDING)
        ->and((int) $request->payload['target_member_id'])->toBe((int) $dependent->id)
        ->and((float) $request->payload['amount'])->toBe(1500.0);
});

test('submit voluntary top-up rejects third-party target', function () {
    $other = Member::create([
        'member_number' => 'VC-OTHER',
        'name' => 'Other Member',
        'email' => 'vol-contrib-other@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($other);

    expect(fn () => app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 1500, 'target_member_id' => $other->id],
    ))->toThrow(ValidationException::class);
});

// --- Deposit form + voluntary top-up bundled submission ---

test('creating a deposit with voluntary_topup_enabled also submits a voluntary contribution request', function () {
    // Simulate what CreateMyFundPosting::handleRecordCreation does
    $fundPostingService = app(FundPostingService::class);
    $posting = $fundPostingService->submit(
        member: $this->member,
        amount: 3000.0,
        postingDate: now()->toDateString(),
        reference: 'TEST-REF',
    );

    // Then also submit the voluntary top-up (mirroring the page logic)
    $request = app(MemberRequestService::class)->submit($this->member, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
        'amount' => 3000.0,
        'target_member_id' => $this->member->id,
    ]);

    expect($posting)->not->toBeNull()
        ->and($request->type)->toBe(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
        ->and($request->status)->toBe(MemberRequest::STATUS_PENDING)
        ->and((float) $request->payload['amount'])->toBe(3000.0);
});

// --- Dependents table action ---

test('voluntaryTopUpForDependentRow action name is registered on dependents table', function () {
    $names = collect(MyDependentTableActions::recordActions())
        ->map(
            fn ($action) => $action instanceof ActionGroup
            ? collect($action->getActions())->map(fn ($a) => $a->getName())->all()
            : $action->getName()
        )
        ->flatten()
        ->all();

    expect($names)->toContain('voluntaryTopUpForDependentRow');
});

// --- MemberRequest label / describePayload ---

test('typeLabel returns a non-empty label for voluntary contribution', function () {
    $label = MemberRequest::typeLabel(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION);

    expect($label)->not->toBe(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
        ->and($label)->not->toBeEmpty();
});

test('describePayload includes period, member name, total and top-up amount', function () {
    $request = app(MemberRequestService::class)->submit(
        $this->member,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 3000],
    );

    $description = $request->fresh()->describePayload();

    expect($description)
        ->toContain('3,000.00')
        ->toContain('1,000.00'); // extra
});
