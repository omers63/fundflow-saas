<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MasterAccountInvariantService;
use App\Services\MemberCashOutService;
use App\Services\MemberCashTransferService;
use App\Services\MemberInvariantService;
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
    MemberCashTransferRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-cash-xfer@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->fromUser = User::create([
        'name' => 'Sender',
        'email' => 'sender-cash-xfer@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->toUser = User::create([
        'name' => 'Recipient',
        'email' => 'recipient-cash-xfer@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->from = Member::create([
        'user_id' => $this->fromUser->id,
        'member_number' => 'MEM-XFER-1',
        'name' => 'Sender Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'opening_cash_balance' => 5000,
    ]);

    $this->to = Member::create([
        'user_id' => $this->toUser->id,
        'member_number' => 'MEM-XFER-2',
        'name' => 'Recipient Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->from);
    $this->accounting->createMemberAccounts($this->to);

    $this->from->cashAccount->update(['balance' => 5000]);
    Account::masterCash()->update(['balance' => 105000]);

    $this->service = app(MemberCashTransferService::class);
});

test('member can submit a peer cash transfer request by recipient name', function () {
    $request = $this->service->submit($this->from, 1000, 'Recipient Member', 'Gift');

    expect($request->status)->toBe('pending')
        ->and((int) $request->to_member_id)->toBe($this->to->id)
        ->and($request->recipient_name)->toBe('Recipient Member')
        ->and((float) $this->from->fresh()->cashAccount->balance)->toBe(5000.0)
        ->and($this->service->availableCashForTransfer($this->from))->toBe(4000.0);
});

test('admin accept moves cash with master mirrors and keeps pool balanced', function () {
    $masterBefore = (float) Account::masterCash()->balance;
    $cashDeltaBefore = app(MasterAccountInvariantService::class)->check()['cash_delta'];
    $request = $this->service->submit($this->from, 1000, 'Recipient Member');

    $this->service->accept($request, $this->admin->id, 'OK');

    $request->refresh();
    $this->from->refresh();
    $this->to->refresh();

    expect($request->status)->toBe('accepted')
        ->and((float) $this->from->cashAccount->balance)->toBe(4000.0)
        ->and((float) $this->to->cashAccount->balance)->toBe(1000.0)
        ->and((float) Account::masterCash()->balance)->toBe($masterBefore)
        ->and(app(MasterAccountInvariantService::class)->check()['cash_delta'])->toBe($cashDeltaBefore)
        ->and(app(MemberInvariantService::class)->check($this->from)['cash_drift'])->toBe(0.0)
        ->and(app(MemberInvariantService::class)->check($this->to)['cash_drift'])->toBe(0.0);
});

test('admin can reject a pending cash transfer', function () {
    $request = $this->service->submit($this->from, 500, 'Recipient Member');

    $this->service->reject($request, $this->admin->id, 'Not allowed');

    expect($request->fresh()->status)->toBe('rejected')
        ->and((float) $this->from->fresh()->cashAccount->balance)->toBe(5000.0)
        ->and((float) $this->to->fresh()->cashAccount->balance)->toBe(0.0);
});

test('member can cancel a pending cash transfer and restore available cash', function () {
    $request = $this->service->submit($this->from, 1000, 'Recipient Member');

    expect($this->service->availableCashForTransfer($this->from))->toBe(4000.0);

    $this->service->cancel($request, $this->fromUser->id);

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($this->service->availableCashForTransfer($this->from->fresh()))->toBe(5000.0)
        ->and((float) $this->from->fresh()->cashAccount->balance)->toBe(5000.0)
        ->and((float) $this->to->fresh()->cashAccount->balance)->toBe(0.0);
});

test('cannot transfer more than available cash', function () {
    expect(fn () => $this->service->submit($this->from, 6000, 'Recipient Member'))
        ->toThrow(InvalidArgumentException::class);
});

test('cannot transfer cash to yourself', function () {
    expect(fn () => $this->service->submit($this->from->fresh(), 100, (string) $this->from->name))
        ->toThrow(InvalidArgumentException::class);
});

test('parent transfer to dependent completes instantly without admin', function () {
    $dependent = Member::create([
        'member_number' => 'MEM-XFER-D',
        'name' => 'Dependent Child',
        'parent_member_id' => $this->from->id,
        'monthly_contribution_amount' => 200,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    $masterBefore = (float) Account::masterCash()->balance;

    $request = $this->service->transferToDependent($this->from, $dependent, 750, 'Allowance');

    expect($request->status)->toBe('accepted')
        ->and((int) $request->to_member_id)->toBe($dependent->id)
        ->and((float) $this->from->fresh()->cashAccount->balance)->toBe(4250.0)
        ->and((float) $dependent->fresh()->cashAccount->balance)->toBe(750.0)
        ->and((float) Account::masterCash()->balance)->toBe($masterBefore);

    $viaSubmit = $this->service->submit($this->from, 250, 'Dependent Child');

    expect($viaSubmit->status)->toBe('accepted')
        ->and((float) $dependent->fresh()->cashAccount->balance)->toBe(1000.0);
});

test('cannot instantly transfer to a non-dependent member via transferToDependent', function () {
    expect(fn () => $this->service->transferToDependent($this->from, $this->to, 100))
        ->toThrow(InvalidArgumentException::class);
});

test('available cash for transfer uses cash balance and does not reserve next emi', function () {
    $loan = Loan::create([
        'member_id' => $this->from->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => now()->addDays(10),
        'status' => 'pending',
    ]);

    expect(app(MemberCashOutService::class)->availableCashForWithdrawal($this->from))->toBe(3000.0)
        ->and($this->service->availableCashForTransfer($this->from))->toBe(5000.0);
});
