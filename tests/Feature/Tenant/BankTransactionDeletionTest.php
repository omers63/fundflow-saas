<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\FundFlowService;
use App\Support\BankTransactionDeletion;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('imported statement line can be deleted without ledger rows', function () {
    $statement = BankStatement::create([
        'filename' => 'import.csv',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $line = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Deposit',
        'amount' => 1500,
        'status' => 'imported',
        'hash' => md5('delete-imported'),
    ]);

    app(BankTransactionDeletion::class)->delete($line);

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and($statement->fresh()->imported_rows)->toBe(0);
});

test('mirrored statement line delete removes linked master bank and cash ledger rows', function () {
    $statement = BankStatement::create([
        'filename' => 'import.csv',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $line = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Wire in',
        'amount' => 2000,
        'status' => 'imported',
        'hash' => md5('delete-mirrored'),
    ]);

    app(FundFlowService::class)->mirrorToCash([$line->id]);

    expect(Account::masterBank()->fresh()->balance)->toBe('2000.00')
        ->and(Account::masterCash()->fresh()->balance)->toBe('2000.00')
        ->and(Transaction::query()->count())->toBe(2);

    app(BankTransactionDeletion::class)->delete($line->fresh());

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0)
        ->and(Account::masterBank()->fresh()->balance)->toBe('0.00')
        ->and(Account::masterCash()->fresh()->balance)->toBe('0.00');
});

test('operational statement lines cannot be deleted from statement lines', function () {
    $statement = BankStatement::create([
        'filename' => 'member-postings',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $line = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Pending deposit',
        'amount' => 500,
        'status' => 'imported',
        'hash' => md5('delete-blocked'),
    ]);

    expect(BankTransactionDeletion::canDelete($line))->toBeFalse();

    app(BankTransactionDeletion::class)->delete($line);
})->throws(InvalidArgumentException::class);

test('posted line delete removes member cash ledger when posted description matches', function () {
    $member = Member::create([
        'member_number' => 'MEM-DEL-01',
        'name' => 'Delete Test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $statement = BankStatement::create([
        'filename' => 'import.csv',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $line = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Member deposit',
        'amount' => 800,
        'status' => 'imported',
        'hash' => md5('delete-posted'),
    ]);

    $flow = app(FundFlowService::class);
    $flow->mirrorToCash([$line->id]);
    AccountingService::withoutMemberCashCollection(
        fn () => $flow->postToMember($line->fresh(), $member),
    );

    expect($member->cashAccount->fresh()->balance)->toBe('800.00')
        ->and(Transaction::query()->count())->toBe(3);

    app(BankTransactionDeletion::class)->delete($line->fresh());

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0)
        ->and($member->cashAccount->fresh()->balance)->toBe('0.00')
        ->and(Account::masterBank()->fresh()->balance)->toBe('0.00')
        ->and(Account::masterCash()->fresh()->balance)->toBe('0.00');
});
