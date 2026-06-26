<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->service = app(AccountingService::class);

    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
});

test('master accounts are created correctly', function () {
    expect(Account::masterCash())->not->toBeNull();
    expect(Account::masterFund())->not->toBeNull();
    expect(Account::masterCash()->balance)->toBe('0.00');
    expect(Account::masterFund()->balance)->toBe('0.00');
});

test('credit increases account balance', function () {
    $account = Account::masterCash();
    $transaction = $this->service->credit($account, 1000.00, 'Test credit');

    expect($account->fresh()->balance)->toBe('1000.00');
    expect($transaction->type)->toBe('credit');
    expect($transaction->amount)->toBe('1000.00');
    expect($transaction->balance_after)->toBe('1000.00');
});

test('debit decreases account balance', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 5000]);

    $transaction = $this->service->debit($account, 2000.00, 'Test debit');

    expect($account->fresh()->balance)->toBe('3000.00');
    expect($transaction->type)->toBe('debit');
    expect($transaction->amount)->toBe('2000.00');
    expect($transaction->balance_after)->toBe('3000.00');
    expect($transaction->getSignedAmount())->toBe(-2000.0);
});

test('reconcile account ledger balances rebuilds balance_after from chronological lines', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 0]);

    $first = $this->service->credit($account, 100, 'First');
    $account->refresh();
    $second = $this->service->credit($account, 50, 'Second');

    $account->update(['balance' => 150]);
    $first->update(['balance_after' => 999]);
    $second->update(['balance_after' => 999]);

    $this->service->reconcileAccountLedgerBalances($account);

    expect((float) $account->fresh()->balance)->toBe(150.0)
        ->and((float) $first->fresh()->balance_after)->toBe(100.0)
        ->and((float) $second->fresh()->balance_after)->toBe(150.0);
});

test('rebuild account balance from transaction lines corrects stored drift', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 0]);

    $this->service->credit($account, 1000, 'In');
    $this->service->debit($account, 1000, 'Out');

    $account->update(['balance' => 40500]);

    $this->service->rebuildAccountBalanceFromTransactionLines($account);

    expect((float) $account->fresh()->balance)->toBe(0.0)
        ->and($this->service->transactionNetForAccount((int) $account->id))->toBe(0.0);
});

test('update transaction adjusts account balance when amount changes', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 1000]);

    $transaction = $this->service->credit($account, 200, 'Original credit');

    $this->service->updateTransaction($transaction, [
        'amount' => 350,
        'type' => 'credit',
        'description' => 'Adjusted credit',
        'transacted_at' => $transaction->transacted_at,
    ]);

    $account->refresh();
    $transaction->refresh();

    expect((float) $account->balance)->toBe(1350.0)
        ->and((float) $transaction->amount)->toBe(350.0)
        ->and($transaction->description)->toBe('Adjusted credit')
        ->and((float) $transaction->balance_after)->toBe(1350.0);
});

test('update transaction can change type and reconcile balance', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 500]);

    $transaction = $this->service->credit($account, 200, 'Will become debit');

    $this->service->updateTransaction($transaction, [
        'amount' => 100,
        'type' => 'debit',
        'description' => 'Corrected to debit',
        'transacted_at' => $transaction->transacted_at,
    ]);

    $account->refresh();

    expect((float) $account->balance)->toBe(400.0);
});

test('credit and debit accept a custom transacted at datetime', function () {
    $account = Account::masterCash();
    $when = Carbon::parse('2023-01-10 09:15:00');

    $credit = $this->service->credit($account, 100.00, 'Dated credit', null, $when);
    $debit = $this->service->debit($account->fresh(), 40.00, 'Dated debit', null, $when->copy()->addHour());

    expect($credit->transacted_at->format('Y-m-d H:i:s'))->toBe('2023-01-10 09:15:00')
        ->and($debit->transacted_at->format('Y-m-d H:i:s'))->toBe('2023-01-10 10:15:00');
});

test('signed amount is positive for credits and negative for debits', function () {
    $account = Account::masterCash();

    $credit = $this->service->credit($account, 500.00, 'Credit');
    $debit = $this->service->debit($account->fresh(), 200.00, 'Debit');

    expect($credit->getSignedAmount())->toBe(500.0)
        ->and($debit->getSignedAmount())->toBe(-200.0);
});

test('transfer moves money between accounts', function () {
    $from = Account::masterCash();
    $from->update(['balance' => 10000]);
    $to = Account::masterFund();

    $this->service->transfer($from, $to, 3000.00, 'Test transfer');

    expect($from->fresh()->balance)->toBe('7000.00');
    expect($to->fresh()->balance)->toBe('3000.00');
});

test('mirror credits for positive amounts', function () {
    $account = Account::masterCash();
    $transaction = $this->service->mirror($account, 5000.00, 'Test mirror credit');

    expect($account->fresh()->balance)->toBe('5000.00');
    expect($transaction->type)->toBe('credit');
});

test('mirror debits for negative amounts', function () {
    $account = Account::masterCash();
    $account->update(['balance' => 10000]);

    $transaction = $this->service->mirror($account, -3000.00, 'Test mirror debit');

    expect($account->fresh()->balance)->toBe('7000.00');
    expect($transaction->type)->toBe('debit');
    expect($transaction->amount)->toBe('3000.00');
});

test('post balanced journal posts multiple legs with shared reference', function () {
    $cash = Account::masterCash();
    $fund = Account::masterFund();
    $cash->update(['balance' => 5000]);
    $fund->update(['balance' => 1000]);

    $exception = new ReconciliationException([
        'exception_code' => 'TEST',
        'domain' => 'contribution',
        'severity' => 'low',
        'status' => 'open',
        'raised_at' => now(),
    ]);
    $exception->save();

    $posted = $this->service->postBalancedJournal(
        [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 250],
            ['account_id' => $fund->id, 'type' => 'credit', 'amount' => 250],
        ],
        'Test balanced journal',
        $exception,
    );

    expect($posted)->toHaveCount(2)
        ->and($cash->fresh()->balance)->toBe('4750.00')
        ->and($fund->fresh()->balance)->toBe('1250.00')
        ->and($posted[0]->reference_type)->toBe(ReconciliationException::class)
        ->and($posted[0]->reference_id)->toBe($exception->id);
});

test('post balanced journal rejects unbalanced legs', function () {
    $cash = Account::masterCash();
    $fund = Account::masterFund();

    expect(fn () => $this->service->postBalancedJournal(
        [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 100],
            ['account_id' => $fund->id, 'type' => 'credit', 'amount' => 50],
        ],
        'Unbalanced',
    ))->toThrow(InvalidArgumentException::class);
});

test('creating member accounts creates cash and fund accounts', function () {
    $member = Member::create([
        'member_number' => 'MEM-TEST',
        'name' => 'Test Member',
        'email' => 'test@example.com',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $this->service->createMemberAccounts($member);

    expect($member->accounts()->count())->toBe(2);
    expect($member->cashAccount)->not->toBeNull();
    expect($member->fundAccount)->not->toBeNull();
    expect($member->cashAccount->type)->toBe('cash');
    expect($member->fundAccount->type)->toBe('fund');
});

test('creating member accounts is idempotent', function () {
    $member = Member::create([
        'member_number' => 'MEM-TEST',
        'name' => 'Test Member',
        'email' => 'test@example.com',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $this->service->createMemberAccounts($member);
    $cashId = $member->cashAccount->id;
    $fundId = $member->fundAccount->id;

    $this->service->createMemberAccounts($member);
    app(LoanLedgerService::class)->ensureMemberAccounts($member);

    expect($member->accounts()->count())->toBe(2)
        ->and($member->fresh()->cashAccount->id)->toBe($cashId)
        ->and($member->fresh()->fundAccount->id)->toBe($fundId);
});

test('debit member cash with master mirror keeps cash pool balanced', function () {
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'member_number' => 'MEM-MIRROR',
        'name' => 'Mirror Member',
        'email' => 'mirror@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);
    $this->service->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 1000]);
    $member->cashAccount->update(['balance' => 1000]);

    AccountingService::withoutMemberCashCollection(function () use ($member): void {
        $this->service->debitMemberCashWithMasterMirror(
            $member->cashAccount,
            250,
            'Test outflow',
            '(test mirror)',
        );
    });

    expect((float) $member->cashAccount->fresh()->balance)->toBe(750.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(750.0);
});

test('credit member fund with master mirror keeps fund pool balanced', function () {
    $member = Member::create([
        'member_number' => 'MEM-FUND-MIRROR',
        'name' => 'Fund Mirror Member',
        'email' => 'fund-mirror@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);
    $this->service->createMemberAccounts($member);

    Account::masterFund()->update(['balance' => 500]);
    $member->fundAccount->update(['balance' => 500]);

    $this->service->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        200,
        'Test fund inflow',
        '(test mirror)',
    );

    expect((float) $member->fundAccount->fresh()->balance)->toBe(700.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(700.0);
});
