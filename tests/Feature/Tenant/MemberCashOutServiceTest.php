<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LoanService;
use App\Services\MemberCashOutService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    Loan::query()->delete();
    CashOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-cashout@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Borrower',
        'email' => 'borrower-cashout@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-CO1',
        'name' => 'Borrower',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->member);
    $this->service = app(MemberCashOutService::class);
    $this->loanService = app(LoanService::class);
});

function disburseLoanProceedsToMemberCash(
    AccountingService $accounting,
    LoanService $loanService,
    Member $member,
    float $amount,
): Loan {
    $accounting->credit($member->fundAccount, max($amount * 2, 30000), 'Seed fund');
    $member->refresh();

    $loan = $loanService->applyForLoan($member, $amount);
    $loanService->approveLoan($loan, $amount);
    $loanService->disburseLoan($loan);

    return $loan->fresh();
}

test('loan disbursement credits member and master cash without automatic payout clearing', function () {
    disburseLoanProceedsToMemberCash($this->accounting, $this->loanService, $this->member, 15000);

    $this->member->refresh();

    expect((float) $this->member->cashAccount->balance)->toBe(15000.0)
        ->and((float) Account::masterCash()->balance)->toBe(115000.0);
});

test('member can submit and admin can accept a cash-out request', function () {
    disburseLoanProceedsToMemberCash($this->accounting, $this->loanService, $this->member, 15000);
    $this->member->refresh();

    $request = $this->service->submit($this->member, 2000, 'Need transfer');

    expect($request->status)->toBe('pending')
        ->and($this->service->availableCashForWithdrawal($this->member))->toBe(12000.0);

    $this->service->accept($request, $this->admin->id);

    $request->refresh();
    $this->member->refresh();

    expect($request->status)->toBe('accepted')
        ->and((float) $this->member->cashAccount->balance)->toBe(13000.0)
        ->and((float) Account::masterCash()->balance)->toBe(113000.0)
        ->and($request->bankTransaction)->not->toBeNull()
        ->and($request->bankTransaction->is_cleared)->toBeFalse()
        ->and((float) $request->bankTransaction->amount)->toBe(-2000.0);
});

test('cash-out amount cannot exceed available cash after emi reserve', function () {
    $loan = disburseLoanProceedsToMemberCash($this->accounting, $this->loanService, $this->member, 15000);
    $this->member->refresh();

    $installment = $loan->installments()->orderBy('installment_number')->first();
    expect($installment)->not->toBeNull();

    $reserved = (float) $installment->amount;
    $available = 15000.0 - $reserved;

    expect($this->service->availableCashForWithdrawal($this->member))->toBe($available);

    expect(fn () => $this->service->submit($this->member, $available + 100))
        ->toThrow(InvalidArgumentException::class);
});

test('pending cash-out requests reduce available withdrawal balance', function () {
    disburseLoanProceedsToMemberCash($this->accounting, $this->loanService, $this->member, 15000);
    $this->member->refresh();

    $this->service->submit($this->member, 2000);

    expect($this->service->availableCashForWithdrawal($this->member))->toBe(12000.0);
});
