<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberStatusService;
use App\Services\MemberWithdrawalSettlementService;
use App\Services\Tenant\MemberRequestService;
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
    CashOutRequest::query()->delete();
    MemberRequest::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 500_000, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->statuses = app(MemberStatusService::class);
    $this->settlement = app(MemberWithdrawalSettlementService::class);
    $this->requests = app(MemberRequestService::class);

    $this->admin = User::create([
        'name' => 'Leave Admin',
        'email' => 'leave-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

function leaveFundMember(
    AccountingService $accounting,
    string $suffix,
    float $cash = 2_000,
    float $fund = 1_000,
    ?int $parentId = null,
    bool $independent = false,
): Member {
    $user = User::create([
        'name' => "Leave {$suffix}",
        'email' => "leave-{$suffix}@fund.test",
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-LV-'.$suffix,
        'name' => "Leave {$suffix}",
        'email' => "leave-{$suffix}@fund.test",
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'parent_member_id' => $parentId,
        'direct_login_enabled' => $independent,
        'is_separated' => $independent,
    ]);

    $accounting->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(function () use ($accounting, $member, $cash): void {
        if ($cash > 0) {
            $accounting->credit($member->fresh()->cashAccount, $cash, 'Seed cash');
        }
    });

    if ($fund > 0) {
        $accounting->creditMemberFundWithMasterMirror(
            $member->fresh()->fundAccount,
            $fund,
            'Seed fund',
            __('(master fund mirror)'),
            $member,
        );
    }

    return $member->fresh();
}

test('non-parent leave auto-accepts cash-out with uncleared bank line', function () {
    $member = leaveFundMember($this->accounting, 'solo', 3_000, 500);

    $this->statuses->withdraw($member, 'Solo exit');

    $member = $member->fresh();
    $cashOut = CashOutRequest::query()->where('member_id', $member->id)->first();

    expect($member->status)->toBe('withdrawn')
        ->and($member->getCashBalance())->toBe(0.0)
        ->and($member->getFundBalance())->toBe(0.0)
        ->and($cashOut)->not->toBeNull()
        ->and($cashOut->status)->toBe('accepted')
        ->and((float) $cashOut->amount)->toBe(3500.0);

    $bankTxn = BankTransaction::query()->where('id', $cashOut->bank_transaction_id)->first();

    expect($bankTxn)->not->toBeNull()
        ->and($bankTxn->is_cleared)->toBeFalse();
});

test('parent electing permanent parent reassigns siblings and withdraws only the leaver', function () {
    $parent = leaveFundMember($this->accounting, 'parent', 2_000, 0);
    $elected = leaveFundMember($this->accounting, 'elect', 1_500, 0, $parent->id, independent: true);
    $sibling = leaveFundMember($this->accounting, 'sib', 800, 0, $parent->id);

    $this->statuses->withdraw($parent, 'Hand off household', plan: [
        'household_mode' => MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT,
        'permanent_parent_member_id' => $elected->id,
        'reason' => 'Hand off household',
    ]);

    expect($parent->fresh()->status)->toBe('withdrawn')
        ->and($elected->fresh()->status)->toBe('active')
        ->and($elected->fresh()->parent_member_id)->toBeNull()
        ->and($sibling->fresh()->status)->toBe('active')
        ->and($sibling->fresh()->parent_member_id)->toBe($elected->id)
        ->and(CashOutRequest::query()->where('member_id', $parent->id)->where('status', 'accepted')->exists())->toBeTrue()
        ->and(CashOutRequest::query()->where('member_id', $elected->id)->exists())->toBeFalse();
});

test('parent withdrawing all dependents settles and withdraws each then the parent', function () {
    $parent = leaveFundMember($this->accounting, 'p-all', 2_000, 0);
    $depA = leaveFundMember($this->accounting, 'dep-a', 900, 100, $parent->id);
    $depB = leaveFundMember($this->accounting, 'dep-b', 700, 0, $parent->id);

    $this->statuses->withdraw($parent, 'Whole household', plan: [
        'household_mode' => MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS,
        'reason' => 'Whole household',
    ]);

    expect($parent->fresh()->status)->toBe('withdrawn')
        ->and($depA->fresh()->status)->toBe('withdrawn')
        ->and($depB->fresh()->status)->toBe('withdrawn')
        ->and($depA->fresh()->getCashBalance())->toBe(0.0)
        ->and($depB->fresh()->getCashBalance())->toBe(0.0)
        ->and(CashOutRequest::query()->where('member_id', $depA->id)->where('status', 'accepted')->exists())->toBeTrue()
        ->and(CashOutRequest::query()->where('member_id', $depB->id)->where('status', 'accepted')->exists())->toBeTrue()
        ->and(CashOutRequest::query()->where('member_id', $parent->id)->where('status', 'accepted')->exists())->toBeTrue();
});

test('parent with dependents cannot leave with self_only household mode', function () {
    $parent = leaveFundMember($this->accounting, 'p-block', 1_000, 0);
    leaveFundMember($this->accounting, 'dep-block', 500, 0, $parent->id);

    expect(fn () => $this->statuses->withdraw($parent, 'Orphan', plan: [
        'household_mode' => MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
    ]))->toThrow(InvalidArgumentException::class);
});

test('hold payout skips auto cash-out accept', function () {
    $member = leaveFundMember($this->accounting, 'hold', 1_200, 300);

    $this->statuses->withdraw($member, 'Review', holdPayout: true);

    expect($member->fresh()->status)->toBe('withdrawn')
        ->and($member->fresh()->payout_frozen_at)->not->toBeNull()
        ->and($member->fresh()->getCashBalance())->toBe(1200.0)
        ->and($member->fresh()->getFundBalance())->toBe(300.0)
        ->and(CashOutRequest::query()->where('member_id', $member->id)->count())->toBe(0);
});

test('frozen member cannot submit leave-fund request from portal', function () {
    $member = leaveFundMember($this->accounting, 'frozen', 500, 0);
    $this->statuses->freeze($member, 'Travel');

    expect(fn () => $this->requests->submit(
        $member->fresh(),
        MemberRequest::TYPE_WITHDRAW_MEMBERSHIP,
        ['reason' => 'Leave while frozen'],
    ))->toThrow(ValidationException::class);
});

test('admin can approve leave-fund request with household plan', function () {
    $parent = leaveFundMember($this->accounting, 'req-p', 2_000, 0);
    $elected = leaveFundMember($this->accounting, 'req-e', 1_200, 0, $parent->id, independent: true);
    leaveFundMember($this->accounting, 'req-s', 400, 0, $parent->id);

    $request = $this->requests->submit($parent, MemberRequest::TYPE_WITHDRAW_MEMBERSHIP, [
        'reason' => 'Planned leave',
        'household_mode' => MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT,
        'permanent_parent_member_id' => $elected->id,
    ]);

    $this->requests->approve($request->fresh(), $this->admin);

    expect($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED)
        ->and($parent->fresh()->status)->toBe('withdrawn')
        ->and($elected->fresh()->parent_member_id)->toBeNull()
        ->and($elected->fresh()->status)->toBe('active');
});

test('reinstate after leave clears balances without reversing prior cash-out', function () {
    $member = leaveFundMember($this->accounting, 'rejoin', 2_500, 0);

    $this->statuses->withdraw($member, 'Exit then return');
    $acceptedAmount = (float) CashOutRequest::query()->where('member_id', $member->id)->value('amount');

    $this->statuses->reinstate($member->fresh(), 'Welcome back');

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->getCashBalance())->toBe(0.0)
        ->and($member->fresh()->getFundBalance())->toBe(0.0)
        ->and(CashOutRequest::query()->where('member_id', $member->id)->where('status', 'accepted')->count())->toBe(1)
        ->and($acceptedAmount)->toBe(2500.0);
});

test('pending cash-out blocks leave readiness', function () {
    $member = leaveFundMember($this->accounting, 'pending-co', 1_000, 0);

    CashOutRequest::query()->create([
        'member_id' => $member->id,
        'amount' => 100,
        'status' => 'pending',
        'notes' => 'open',
    ]);

    $assessment = $this->settlement->assess($member);

    expect($assessment['can_withdraw'])->toBeFalse()
        ->and($assessment['blockers'])->not->toBeEmpty();
});
