<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Services\AccountingService;
use App\Services\MasterExpenseDisbursementService;
use App\Support\BankClearing\BankClearingQueuePresenter;
use Illuminate\Support\Facades\App;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    App::setLocale('en');

    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5_000, 'is_master' => true]);
});

it('labels bank file and operational queue items', function () {
    $statement = BankStatement::create([
        'filename' => 'presenter-import.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bankFile = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Salary',
        'amount' => 500,
        'status' => 'imported',
        'hash' => md5('presenter-bank-file'),
    ]);

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Presenter reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Presenter expense',
    );

    $operational = BankTransaction::query()->whereNotNull('expense_disbursement_id')->firstOrFail();

    expect(BankClearingQueuePresenter::kindLabel($bankFile))->toBe('Bank import')
        ->and(BankClearingQueuePresenter::kindLabel($operational))->toBe('Expense')
        ->and(BankClearingQueuePresenter::sliceLabel($bankFile))->toBe('From bank file')
        ->and(BankClearingQueuePresenter::sliceLabel($operational))->toBe('From operations')
        ->and(BankClearingQueuePresenter::suggestedActionLabel($operational))->toBe('Match');
});
