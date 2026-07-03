<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\LoanService;
use App\Services\MemberStatusService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\LegacyMemberStatusMapper;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->statuses = app(MemberStatusService::class);
    $this->accounting = app(AccountingService::class);
    $this->loanService = app(LoanService::class);
    $this->lifecycle = app(LoanLifecycleService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    CashOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

function membershipTestEligibleMember(AccountingService $accounting, float $fundBalance = 30_000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Membership Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => $fundBalance]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $member->joined_at->copy()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if ((float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions($month, $year)) {
            Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => $member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }

    return $member->fresh()->load(['fundAccount', 'cashAccount']);
}

function membershipTestActiveLoan(LoanService $service, AccountingService $accounting, float $amount = 10_000): Loan
{
    $member = membershipTestEligibleMember($accounting, 30_000);
    Account::masterFund()->update(['balance' => 100_000]);
    Account::masterCash()->update(['balance' => 100_000]);

    $loan = $service->applyForLoan($member, $amount);
    $service->approveLoan($loan, $amount);
    $service->disburseLoan($loan);

    return $loan->fresh(['member', 'installments']);
}

test('freeze with fund cash-out transfers fund balance and accepts cash-out on freeze date', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        2_500,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $freezeDate = Carbon::parse('2025-04-20')->endOfDay();

    $this->statuses->freeze($member, 'Travel abroad', $freezeDate, cashOutFund: true);

    $member = $member->fresh();
    $cashOut = CashOutRequest::query()->where('member_id', $member->id)->first();

    expect($member->status)->toBe('inactive')
        ->and($member->frozen_at?->toDateString())->toBe('2025-04-20')
        ->and($member->getFundBalance())->toBe(0.0)
        ->and($member->getCashBalance())->toBe(0.0)
        ->and($cashOut)->not->toBeNull()
        ->and($cashOut->status)->toBe('accepted')
        ->and((float) $cashOut->amount)->toBe(2500.0)
        ->and($cashOut->notes)->toBe('Travel abroad')
        ->and($cashOut->reviewed_at?->toDateString())->toBe('2025-04-20');
});

test('freeze and unfreeze membership without cash-out', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        1_500,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $freezeDate = BusinessDay::today()->endOfDay();

    $this->statuses->freeze($member, 'Travel abroad', $freezeDate);

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->contribution_cycles_active)->toBeFalse()
        ->and($member->fresh()->frozen_at?->toDateString())->toBe($freezeDate->toDateString())
        ->and($member->fresh()->getFundBalance())->toBe(1500.0)
        ->and(CashOutRequest::query()->where('member_id', $member->id)->count())->toBe(0);

    $this->statuses->unfreeze($member->fresh());

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->frozen_at)->toBeNull();
});

test('withdraw early-settles active loan and creates pending cash-out', function () {
    $loan = membershipTestActiveLoan($this->loanService, $this->accounting, 10_000);
    $member = $loan->member->fresh();
    $required = app(LoanEarlySettlementService::class)->requiredCash($loan);
    $member->cashAccount()->update(['balance' => $required + 2_000]);
    $member->fundAccount()->update(['balance' => 1_000]);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $this->statuses->withdraw($member->fresh(), 'Voluntary exit');

    $member = $member->fresh();
    $loan = $loan->fresh();

    expect($member->status)->toBe('withdrawn')
        ->and($member->payout_frozen_at)->toBeNull()
        ->and($loan->status)->toBe('early_settled')
        ->and($member->getFundBalance())->toBe(0.0);

    $cashOut = CashOutRequest::query()->where('member_id', $member->id)->first();

    expect($cashOut)->not->toBeNull()
        ->and($cashOut->status)->toBe('pending')
        ->and((float) $cashOut->amount)->toBe($member->getCashBalance())
        ->and($member->getCashBalance())->toBeGreaterThan(0);
});

test('withdraw with hold payout settles loan but keeps balances for review', function () {
    $loan = membershipTestActiveLoan($this->loanService, $this->accounting, 10_000);
    $member = $loan->member->fresh();
    $required = app(LoanEarlySettlementService::class)->requiredCash($loan);
    $member->cashAccount()->update(['balance' => $required + 500]);

    $this->statuses->withdraw($member->fresh(), 'Policy violation', holdPayout: true);

    $member = $member->fresh();

    expect($member->status)->toBe('withdrawn')
        ->and($member->payout_frozen_at)->not->toBeNull()
        ->and($member->getCashBalance())->toBe(500.0)
        ->and(CashOutRequest::query()->where('member_id', $member->id)->count())->toBe(0);
});

test('withdraw is blocked when member guarantees an active unreleased loan', function () {
    $borrower = membershipTestEligibleMember($this->accounting, 30_000);
    $guarantor = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($guarantor);
    Account::masterFund()->update(['balance' => 100_000]);
    Account::masterCash()->update(['balance' => 100_000]);

    $loan = $this->lifecycle->applyForLoan($borrower, 10_000, null, $guarantor->id);
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    expect(fn () => $this->statuses->withdraw($guarantor->fresh(), 'Leaving'))
        ->toThrow(InvalidArgumentException::class);
});

test('withdraw is blocked when member has an open loan application', function () {
    $member = membershipTestEligibleMember($this->accounting, 30_000);

    $this->lifecycle->applyForLoan($member, 5_000);

    expect(fn () => $this->statuses->withdraw($member->fresh(), 'Leaving'))
        ->toThrow(InvalidArgumentException::class);
});

test('restore inactive returns active when on administrative hold', function () {
    $member = Member::factory()->create([
        'status' => 'inactive',
        'frozen_at' => null,
        'contribution_cycles_active' => false,
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);

    $this->statuses->restoreInactive($member);

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue();
});

test('guarantor transfer suspend keeps contribution cycles active', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $this->statuses->suspendForGuarantorTransfer($member);

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->frozen_at)->toBeNull()
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue();
});

test('terminate delegates to withdraw with payout hold', function () {
    $member = Member::create([
        'member_number' => 'MEM-TERM-'.uniqid(),
        'name' => 'Terminate Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->fresh()->cashAccount,
        500,
        'Seed cash',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $this->statuses->terminate($member->fresh(), 'Policy violation');

    $member->refresh();

    expect($member->status)->toBe('withdrawn')
        ->and($member->payout_frozen_at)->not->toBeNull()
        ->and($member->getCashBalance())->toBe(500.0);
});

test('reinstate clears balances after withdrawal', function () {
    $member = Member::create([
        'member_number' => 'MEM-REIN-'.uniqid(),
        'name' => 'Reinstate Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->fresh()->cashAccount,
        500,
        'Seed cash',
        '(test)',
        $member,
        now(),
        $member->id,
    );
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fresh()->fundAccount,
        800,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $this->statuses->terminate($member->fresh(), 'Policy violation');

    $this->statuses->reinstate($member->fresh(), 'Board approved return');

    $member->refresh();

    expect($member->status)->toBe('active')
        ->and($member->payout_frozen_at)->toBeNull()
        ->and($member->getCashBalance())->toBe(0.0)
        ->and($member->getFundBalance())->toBe(0.0);
});

test('legacy inactive import maps to inactive status', function () {
    expect(LegacyMemberStatusMapper::normalize('inactive'))->toBe('inactive');
});

test('withdraw records a custom withdrawal date on status and settlement', function () {
    $member = Member::create([
        'member_number' => 'MEM-WD-DATE-'.uniqid(),
        'name' => 'Withdraw Date Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->fresh()->cashAccount,
        1_000,
        'Seed cash',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $withdrawDate = Carbon::parse('2025-06-15')->endOfDay();

    $this->statuses->withdraw($member->fresh(), 'Leaving', holdPayout: true, withdrawDate: $withdrawDate);

    $member = $member->fresh();

    expect($member->status)->toBe('withdrawn')
        ->and($member->status_changed_at?->toDateString())->toBe('2025-06-15')
        ->and($member->payout_frozen_at?->toDateString())->toBe('2025-06-15');
});

test('legacy delinquent import maps to active status', function () {
    expect(LegacyMemberStatusMapper::normalize('delinquent'))->toBe('active')
        ->and(LegacyMemberStatusMapper::normalize('suspended'))->toBe('inactive')
        ->and(LegacyMemberStatusMapper::normalize('terminated'))->toBe('withdrawn');
});
