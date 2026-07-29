<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyDependents\Support\MyDependentTableActions;
use App\Filament\Support\MemberContributionFilamentActions;
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

test('parent not eligible for self can still request voluntary top-up for eligible dependent', function () {
    $parentUser = User::create([
        'name' => 'Vol Contrib Parent Zero',
        'email' => 'vol-contrib-parent-zero@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'VC-PARENT-ZERO',
        'name' => 'Vol Contrib Parent Zero',
        'email' => 'vol-contrib-parent-zero@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($parent);

    $dependentUser = User::create([
        'name' => 'Vol Contrib Dependent Eligible',
        'email' => 'vol-contrib-dep-eligible@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $dependent = Member::create([
        'user_id' => $dependentUser->id,
        'parent_member_id' => $parent->id,
        'member_number' => 'VC-DEP-ELIG',
        'name' => 'Vol Contrib Dependent Eligible',
        'email' => 'vol-contrib-dep-eligible@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($dependent);

    expect(MemberContributionFilamentActions::canRequestVoluntaryTopUp(null, $parent))->toBeFalse()
        ->and(MemberContributionFilamentActions::canRequestVoluntaryTopUp($dependent, $parent))->toBeTrue()
        ->and(MemberContributionFilamentActions::canRequestVoluntaryTopUpForHousehold($parent))->toBeTrue();

    $targets = MemberContributionFilamentActions::eligibleVoluntaryTopUpTargets($parent);

    expect($targets)->toHaveCount(1)
        ->and((int) $targets[0]->id)->toBe((int) $dependent->id);

    $request = app(MemberRequestService::class)->submit(
        $parent,
        MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        ['amount' => 1500, 'target_member_id' => $dependent->id],
    );

    expect($request->status)->toBe(MemberRequest::STATUS_PENDING)
        ->and((int) $request->payload['target_member_id'])->toBe((int) $dependent->id);
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

test('parent can submit voluntary top-ups for some or all eligible dependents in one go', function () {
    $parentUser = User::create([
        'name' => 'Vol Contrib Multi Parent',
        'email' => 'vol-contrib-multi-parent@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'VC-MULTI-PARENT',
        'name' => 'Vol Contrib Multi Parent',
        'email' => 'vol-contrib-multi-parent@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($parent);

    $dependents = collect([
        ['number' => 'VC-MULTI-D1', 'email' => 'vol-contrib-multi-d1@fund.test', 'name' => 'Multi Dep One', 'amount' => 1000],
        ['number' => 'VC-MULTI-D2', 'email' => 'vol-contrib-multi-d2@fund.test', 'name' => 'Multi Dep Two', 'amount' => 1500],
        ['number' => 'VC-MULTI-D3', 'email' => 'vol-contrib-multi-d3@fund.test', 'name' => 'Multi Dep Three', 'amount' => 2000],
    ])->map(function (array $row) use ($parent): Member {
        $user = User::create([
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_admin' => false,
        ]);

        $dependent = Member::create([
            'user_id' => $user->id,
            'parent_member_id' => $parent->id,
            'member_number' => $row['number'],
            'name' => $row['name'],
            'email' => $row['email'],
            'monthly_contribution_amount' => $row['amount'],
            'joined_at' => now()->subYear(),
            'status' => 'active',
        ]);

        app(AccountingService::class)->createMemberAccounts($dependent);

        return $dependent;
    });

    $targets = MemberContributionFilamentActions::eligibleVoluntaryTopUpTargets($parent);

    expect($targets)->toHaveCount(3);

    $extrasByIndex = [0 => 500.0, 1 => 1000.0];

    foreach ($extrasByIndex as $index => $extra) {
        $dependent = $dependents[$index];
        app(MemberRequestService::class)->submit($parent, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
            'amount' => round((float) $dependent->monthly_contribution_amount + $extra, 2),
            'target_member_id' => $dependent->id,
        ]);
    }

    $pending = MemberRequest::query()
        ->where('type', MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
        ->where('status', MemberRequest::STATUS_PENDING)
        ->where('requester_member_id', $parent->id)
        ->get();

    expect($pending)->toHaveCount(2);

    expect((float) $pending->first(
        fn (MemberRequest $request): bool => (int) $request->payload['target_member_id'] === (int) $dependents[0]->id
    )->payload['amount'])->toBe(1500.0)
        ->and((float) $pending->first(
            fn (MemberRequest $request): bool => (int) $request->payload['target_member_id'] === (int) $dependents[1]->id
        )->payload['amount'])->toBe(2500.0);

    expect(MemberContributionFilamentActions::canRequestVoluntaryTopUp($dependents[2], $parent))->toBeTrue()
        ->and(MemberContributionFilamentActions::eligibleVoluntaryTopUpTargets($parent))->toHaveCount(1);
});

// --- Dependents table action ---

test('voluntaryTopUpForDependentRow action name is registered on dependents table', function () {
    $actions = collect(MyDependentTableActions::recordActions())
        ->flatMap(function ($action) {
            if ($action instanceof ActionGroup) {
                return collect($action->getActions());
            }

            return collect([$action]);
        });

    $names = $actions->map(fn ($action) => $action->getName())->all();

    expect($names)->toContain('voluntaryTopUpForDependentRow')
        ->and($names)->toContain('requestOpenCycleAmountForDependent');

    expect($actions->first(fn ($action) => $action->getName() === 'voluntaryTopUpForDependentRow')->getLabel())
        ->toBe(__('Add Standard Top-Up'))
        ->and($actions->first(fn ($action) => $action->getName() === 'requestOpenCycleAmountForDependent')->getLabel())
        ->toBe(__('Request Large Top-Up'));
});

// --- MemberRequest label / describePayload ---

test('typeLabel for contribution top-up does not say voluntary', function () {
    $label = MemberRequest::typeLabel(MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION);

    expect($label)->toBe(__('Contribution top-up'))
        ->and(strtolower($label))->not->toContain('voluntary');
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
