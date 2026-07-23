<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\BankImportPostAsService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    ReconciliationException::query()->delete();

    Account::factory()->masterCash()->withBalance(0)->create();
    Account::factory()->masterFund()->withBalance(50_000)->create();
    Account::factory()->masterBank()->withBalance(0)->create();
    Account::factory()->masterExpense()->withBalance(5_000)->create();
    Account::factory()->masterInvest()->withBalance(0)->create();
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 5_000, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->service = app(BankImportPostAsService::class);

    $this->statement = BankStatement::create([
        'filename' => 'post-as-import.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 0,
        'imported_rows' => 0,
        'duplicate_rows' => 0,
    ]);
});

function createImportedLine(BankStatement $statement, float $amount, string $description = 'CSV line'): BankTransaction
{
    return BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2025-11-05',
        'description' => $description,
        'amount' => $amount,
        'status' => 'imported',
        'hash' => md5('post-as-'.uniqid('', true)),
        'is_cleared' => false,
    ]);
}

test('post as invest return records return and clears the csv line', function () {
    $imported = createImportedLine($this->statement, 1_250, 'Investment proceeds');

    $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_INVEST_RETURN,
        'Investment proceeds',
        null,
        '2025-11-05',
    );

    $imported->refresh();

    expect($imported->is_cleared)->toBeTrue()
        ->and($imported->invest_return_id)->not->toBeNull()
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(51_250.0)
        ->and(ReconciliationException::query()
            ->whereIn('exception_code', ['MASTER_CASH_POOL_DRIFT', 'MEMBER_CASH_DRIFT'])
            ->open()
            ->exists())->toBeFalse();

    $ops = BankTransaction::query()->whereNotNull('invest_return_id')->first();

    expect($ops)->not->toBeNull()
        ->and($ops->is_cleared)->toBeTrue()
        ->and((float) $ops->amount)->toBe(1_250.0);
});

test('post as expense out debits expense and clears the csv line', function () {
    $imported = createImportedLine($this->statement, -400, 'Vendor payment');

    $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_EXPENSE_OUT,
        'Vendor payment',
    );

    $imported->refresh();

    expect($imported->is_cleared)->toBeTrue()
        ->and($imported->expense_disbursement_id)->not->toBeNull()
        ->and((float) Account::masterExpense()->fresh()->balance)->toBe(4_600.0);

    $ops = BankTransaction::query()->whereNotNull('expense_disbursement_id')->first();

    expect($ops)->not->toBeNull()
        ->and($ops->is_cleared)->toBeTrue()
        ->and((float) $ops->amount)->toBe(-400.0);
});

test('post as member deposit mirrors cash and credits the member', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $imported = createImportedLine($this->statement, 3, 'Deposit from member #23');

    $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_MEMBER_DEPOSIT,
        'Deposit from member #23',
        $member->id,
    );

    $imported->refresh();

    expect($imported->status)->toBe('posted')
        ->and($imported->is_cleared)->toBeTrue()
        ->and($imported->member_id)->toBe($member->id)
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(3.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(3.0)
        ->and($imported->cleared_at->toDateString())->toBe('2025-11-05')
        ->and(Transaction::query()
            ->where('reference_type', BankTransaction::class)
            ->where('reference_id', $imported->id)
            ->get()
            ->every(fn ($leg) => $leg->transacted_at->toDateString() === '2025-11-05'))->toBeTrue()
        ->and(ReconciliationException::query()
            ->whereIn('exception_code', ['MASTER_CASH_POOL_DRIFT', 'MEMBER_CASH_DRIFT'])
            ->open()
            ->exists())->toBeFalse();
});

test('post as rejects type that does not match line sign', function () {
    $imported = createImportedLine($this->statement, 100, 'Inbound');

    expect(fn () => $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_EXPENSE_OUT,
        'Wrong type',
    ))->toThrow(InvalidArgumentException::class);
});

test('post as requires member for member deposit', function () {
    $imported = createImportedLine($this->statement, 50, 'Deposit');

    expect(fn () => $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_MEMBER_DEPOSIT,
        'Deposit',
    ))->toThrow(InvalidArgumentException::class);
});

test('post as rejects ineligible statement lines', function () {
    $imported = createImportedLine($this->statement, 100, 'Already posted');
    $imported->update(['status' => 'posted', 'is_cleared' => true]);

    expect(fn () => $this->service->postAs(
        $imported,
        BankImportPostAsService::TYPE_INVEST_RETURN,
        'Too late',
    ))->toThrow(InvalidArgumentException::class);
});
