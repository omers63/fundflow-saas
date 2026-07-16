<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\CashOutBankClearedNotification;
use App\Services\AccountingService;
use App\Services\LoanService;
use App\Services\MemberCashOutService;
use App\Services\MemberInvariantService;
use Carbon\Carbon;
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

test('accepted cash-out is counted once in member cash invariant', function () {
    disburseLoanProceedsToMemberCash($this->accounting, $this->loanService, $this->member, 15000);
    $this->member->refresh();

    $request = $this->service->submit($this->member, 2000);
    $this->service->accept($request, $this->admin->id);

    $result = app(MemberInvariantService::class)->check($this->member->fresh());

    expect($result['cash_drift'])->toBe(0.0)
        ->and($result['expected_cash'])->toBe($result['actual_cash'])
        ->and($result['components']['cash_outs'])->toBe(2000.0);
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

test('inactive member fund balance cash-out transfers fund to cash and accepts on the chosen date', function () {
    $this->member->update([
        'status' => 'inactive',
        'contribution_cycles_active' => false,
    ]);
    $this->accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        3500,
        'Seed fund',
        '(mirror)',
        $this->member,
        now(),
        $this->member->id,
    );
    $this->member->refresh();

    $cashOutAt = Carbon::parse('2025-03-10')->endOfDay();
    $request = $this->service->submitFundBalanceCashOut(
        $this->member,
        'Exit payout',
        $cashOutAt,
        $this->admin->id,
    );

    $this->member->refresh();

    expect($request->status)->toBe('accepted')
        ->and((float) $request->amount)->toBe(3500.0)
        ->and($request->notes)->toBe('Exit payout')
        ->and($request->reviewed_at?->toDateString())->toBe('2025-03-10')
        ->and($this->member->getFundBalance())->toBe(0.0)
        ->and($this->member->getCashBalance())->toBe(0.0)
        ->and($request->bankTransaction)->not->toBeNull();
});

test('fund cash-out rejects active members', function () {
    $this->accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        1000,
        'Seed fund',
        '(mirror)',
        $this->member,
        now(),
        $this->member->id,
    );

    expect(fn () => $this->service->submitFundBalanceCashOut($this->member->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('fund cash-out rejects payout frozen members', function () {
    $this->member->update([
        'status' => 'withdrawn',
        'payout_frozen_at' => now(),
    ]);
    $this->accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        1000,
        'Seed fund',
        '(mirror)',
        $this->member,
        now(),
        $this->member->id,
    );

    expect(fn () => $this->service->submitFundBalanceCashOut($this->member->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('clearing an accepted cash-out notifies the member', function () {
    AccountingService::withoutMemberCashCollection(function (): void {
        $this->accounting->creditMemberCashWithMasterMirror(
            $this->member->cashAccount,
            5000,
            'Seed cash for withdrawal',
            '(mirror)',
            null,
            null,
            $this->member->id,
        );
    });
    $this->member->refresh();

    $request = $this->service->submit($this->member, 2000, 'Need transfer');
    $this->service->accept($request, $this->admin->id);

    $unclearedTxn = $request->fresh()->bankTransaction;
    expect($unclearedTxn)->not->toBeNull()
        ->and($unclearedTxn->is_cleared)->toBeFalse();

    $importedTxn = BankTransaction::create([
        'bank_statement_id' => $unclearedTxn->bank_statement_id,
        'transaction_date' => $unclearedTxn->transaction_date,
        'description' => 'Bank import cash-out match',
        'amount' => -2000,
        'status' => 'imported',
        'hash' => md5('cashout-imported-match-' . microtime()),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    $this->service->clearTransaction($unclearedTxn, $importedTxn);

    expect($unclearedTxn->fresh()->is_cleared)->toBeTrue();

    Notification::assertSentTo($this->memberUser, CashOutBankClearedNotification::class);
});
