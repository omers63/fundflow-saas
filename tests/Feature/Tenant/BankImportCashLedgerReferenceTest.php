<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\BankImportCashLedgerReferenceBackfillService;
use App\Services\FundFlowService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    Transaction::query()->delete();
    ReconciliationException::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->fundFlow = app(FundFlowService::class);
});

function createImportLine(float $amount, string $description = 'CSV deposit'): BankTransaction
{
    $statement = BankStatement::create([
        'filename' => 'cash-ledger-ref.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    return BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => $description,
        'amount' => $amount,
        'status' => 'imported',
        'hash' => md5('cash-ledger-ref-'.uniqid('', true)),
        'is_cleared' => false,
    ]);
}

test('mirror and post to member attach bank transaction as linked source on cash legs', function () {
    $member = Member::factory()->create(['status' => 'active', 'monthly_contribution_amount' => 0]);
    $this->accounting->createMemberAccounts($member);

    $imported = createImportLine(3, 'Deposit from member #23');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->fundFlow->ensureMirroredAndPostToMember($imported, $member),
    );

    $imported = $imported->fresh();
    $legs = Transaction::query()
        ->where('reference_type', BankTransaction::class)
        ->where('reference_id', $imported->id)
        ->with('account')
        ->get();

    expect($legs)->toHaveCount(3)
        ->and($legs->pluck('account.type')->all())->toEqualCanonicalizing(['bank', 'cash', 'cash'])
        ->and(ReconciliationException::query()->where('exception_code', 'UNBALANCED_ENTRY')->open()->exists())->toBeFalse()
        ->and($imported->masterCashTransaction?->reference_id)->toBe($imported->id)
        ->and($imported->masterCashTransaction?->reference_type)->toBe(BankTransaction::class);
});

test('backfill links historical null-reference cash legs to the csv bank line', function () {
    $member = Member::factory()->create(['status' => 'active', 'monthly_contribution_amount' => 0]);
    $this->accounting->createMemberAccounts($member);

    $imported = createImportLine(3, 'Deposit from member #23');

    // Simulate legacy posting: bank referenced, cash legs null-ref, FK on master cash.
    $bankLeg = $this->accounting->credit(
        Account::masterBank(),
        3,
        FundFlowService::mirrorToCashLedgerDescription($imported),
        $imported,
    );
    $masterCash = $this->accounting->credit(
        Account::masterCash(),
        3,
        FundFlowService::mirrorToCashLedgerDescription($imported),
    );
    $memberCash = $this->accounting->credit(
        $member->cashAccount,
        3,
        FundFlowService::postedToMemberLedgerDescription($imported),
        null,
        null,
        $member->id,
    );

    $imported->update([
        'status' => 'posted',
        'member_id' => $member->id,
        'master_cash_transaction_id' => $masterCash->id,
        'is_cleared' => true,
    ]);

    expect($masterCash->fresh()->reference_id)->toBeNull()
        ->and($memberCash->fresh()->reference_id)->toBeNull()
        ->and($bankLeg->reference_id)->toBe($imported->id);

    $result = app(BankImportCashLedgerReferenceBackfillService::class)->backfill();

    expect($result['master_cash'])->toBe(1)
        ->and($result['member_cash'])->toBe(1)
        ->and($masterCash->fresh()->reference_type)->toBe(BankTransaction::class)
        ->and($masterCash->fresh()->reference_id)->toBe($imported->id)
        ->and($memberCash->fresh()->reference_type)->toBe(BankTransaction::class)
        ->and($memberCash->fresh()->reference_id)->toBe($imported->id);
});
