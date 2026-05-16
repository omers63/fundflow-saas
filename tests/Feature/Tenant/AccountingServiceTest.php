<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
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
