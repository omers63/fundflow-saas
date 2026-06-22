<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\ContributionCollectionCycleService;
use App\Services\FundFlowService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use App\Support\BankTransactionWorkflow;
use Illuminate\Support\Collection;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->fundPostings = app(FundPostingService::class);
    $this->matching = app(BankClearingMatchService::class);
});

test('auto match selected pairs a manual two-line selection', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-01',
        'name' => 'Bulk Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 2500, '2026-05-10');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'bulk-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-10',
        'description' => 'Imported bulk match',
        'amount' => 2500,
        'status' => 'imported',
        'hash' => md5('bulk-manual-pair'),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    $stats = $this->matching->autoMatchSelected(Collection::make([$uncleared, $imported]));

    expect($stats['manual_pair'])->toBeTrue()
        ->and($stats['matched'])->toBe(1)
        ->and($uncleared->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->fund_posting_id)->toBe($posting->id);
});

test('auto match selected matches uncleared lines with a unique imported counterpart', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-02',
        'name' => 'Auto Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 900, '2026-05-11');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'auto-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-11',
        'description' => 'Imported auto match',
        'amount' => 900,
        'status' => 'imported',
        'hash' => md5('bulk-auto-match'),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    $stats = $this->matching->autoMatchSelected(Collection::make([$uncleared]));

    expect($stats['matched'])->toBe(1)
        ->and($stats['ambiguous'])->toBe(0)
        ->and($uncleared->fresh()->is_cleared)->toBeTrue();
});

test('posted bank lines assigned to a member are not unmatched import targets', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-05',
        'name' => 'Direct Post Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $statement = BankStatement::create([
        'filename' => 'direct-post.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Salary transfer',
        'amount' => 5500,
        'status' => 'posted',
        'member_id' => $member->id,
        'is_cleared' => true,
        'cleared_at' => now(),
        'hash' => md5('direct-posted-line'),
    ]);

    $scan = $this->matching->scanMatchExceptions();

    expect($scan['unmatched_imported'])->not->toContain($imported->id);
});

test('mirrored bank statement lines are eligible match targets', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-04',
        'name' => 'Mirrored Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 750, '2026-05-13');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'real-bank-may.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-13',
        'description' => 'Mirrored deposit',
        'amount' => 750,
        'status' => 'mirrored',
        'hash' => md5('mirrored-target'),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    expect($this->matching->isImportedMatchCandidate($imported))->toBeTrue();

    $masterBank = Account::masterBank();
    $bankBalanceBeforeMatch = (float) $masterBank->balance;

    $this->matching->clearMatchPair($uncleared, $imported);

    $imported = $imported->fresh();

    expect($uncleared->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fund_posting_id)->toBe($posting->id)
        ->and($imported->status)->toBe('posted')
        ->and($imported->member_id)->toBe($member->id)
        ->and($imported->master_bank_transaction_id)->not->toBeNull()
        ->and((float) $masterBank->fresh()->balance)->toBe($bankBalanceBeforeMatch + 750);

    $bankLedger = $imported->masterBankTransaction;

    expect($bankLedger)->not->toBeNull()
        ->and($bankLedger->account_id)->toBe($masterBank->id)
        ->and($bankLedger->member_id)->toBe($member->id)
        ->and($bankLedger->type)->toBe('credit');
});

test('matched bank import is match-only and cannot be posted to cash or member', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-06',
        'name' => 'Match Only Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 1800, '2026-05-14');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'match-only.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-14',
        'description' => 'Bank deposit',
        'amount' => 1800,
        'status' => 'imported',
        'hash' => md5('match-only-import'),
        'is_cleared' => false,
    ]);

    $masterBank = Account::masterBank();
    $masterCash = Account::masterCash();
    $cashAfterAccept = (float) $masterCash->fresh()->balance;
    $bankBeforeMatch = (float) $masterBank->balance;

    $this->matching->clearMatchPair($uncleared, $imported);

    $imported = $imported->fresh();

    expect(BankTransactionWorkflow::canPostToCash($imported))->toBeFalse()
        ->and(BankTransactionWorkflow::canPostToMember($imported))->toBeFalse()
        ->and($imported->fund_posting_id)->toBe($posting->id)
        ->and($imported->status)->toBe('posted')
        ->and($imported->master_bank_transaction_id)->not->toBeNull()
        ->and((float) $masterBank->fresh()->balance)->toBe($bankBeforeMatch + 1800)
        ->and((float) $masterCash->fresh()->balance)->toBe($cashAfterAccept);

    $fundFlow = app(FundFlowService::class);

    expect($fundFlow->mirrorToCash([$imported->id]))->toBe(0);

    expect(fn () => $fundFlow->ensureMirroredAndPostToMember($imported, $member))
        ->toThrow(InvalidArgumentException::class);

    $scan = $this->matching->scanMatchExceptions();

    expect($scan['unmatched_imported'])->not->toContain($imported->id);
});

test('synthetic postings appear only on pending bank match until cleared', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-09',
        'name' => 'Tab Scope Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 950, '2026-05-16');
    $this->fundPostings->accept($posting);

    $synthetic = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'tab-scope.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-16',
        'description' => 'Bank line',
        'amount' => 950,
        'status' => 'imported',
        'hash' => md5('tab-scope-import'),
        'is_cleared' => false,
    ]);

    $realScope = $this->matching->applyRealBankStatementLinesScope(BankTransaction::query())->pluck('id');
    $pendingScope = $this->matching->applyPendingOperationalClearanceScope(BankTransaction::query())->pluck('id');

    expect($realScope)->not->toContain($synthetic->id)
        ->and($realScope)->toContain($imported->id)
        ->and($pendingScope)->toContain($synthetic->id)
        ->and($pendingScope)->not->toContain($imported->id);

    $this->matching->clearMatchPair($synthetic, $imported);

    $realScope = $this->matching->applyRealBankStatementLinesScope(BankTransaction::query())->pluck('id');
    $pendingScope = $this->matching->applyPendingOperationalClearanceScope(BankTransaction::query())->pluck('id');

    expect($realScope)->toContain($imported->fresh()->id)
        ->and($realScope)->not->toContain($synthetic->fresh()->id)
        ->and($pendingScope)->not->toContain($synthetic->fresh()->id);
});

test('synthetic fund posting lines cannot be posted to cash', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-07',
        'name' => 'Synthetic Posting Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 600, '2026-05-15');
    $synthetic = $posting->bankTransaction->fresh();

    expect(BankTransactionWorkflow::canPostToCash($synthetic))->toBeFalse()
        ->and(BankTransactionWorkflow::canPostToMember($synthetic))->toBeFalse();
});

test('cash-out matched import is match-only', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-08',
        'name' => 'Cash Out Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        5000,
        'Seed cash',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );
    $member->refresh();

    $cashOuts = app(MemberCashOutService::class);
    $request = $cashOuts->submit($member, 400, 'Need funds');
    $cashOuts->accept($request, reviewedBy: null);

    $uncleared = $request->fresh()->bankTransaction;
    $statement = BankStatement::create([
        'filename' => 'cash-out-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Withdrawal',
        'amount' => -400,
        'status' => 'imported',
        'hash' => md5('cash-out-match-import'),
        'is_cleared' => false,
    ]);

    $masterBank = Account::masterBank();
    $bankBeforeMatch = (float) $masterBank->balance;

    $this->matching->clearMatchPair($uncleared, $imported);

    $imported = $imported->fresh();

    expect(BankTransactionWorkflow::canPostToCash($imported))->toBeFalse()
        ->and(BankTransactionWorkflow::canPostToMember($imported))->toBeFalse()
        ->and($imported->cash_out_request_id)->toBe($request->id)
        ->and($imported->status)->toBe('posted')
        ->and($imported->member_id)->toBe($member->id)
        ->and($imported->master_bank_transaction_id)->not->toBeNull()
        ->and((float) $masterBank->fresh()->balance)->toBe($bankBeforeMatch - 400);

    expect($imported->masterBankTransaction?->type)->toBe('debit');
});

test('expense disbursement matched import is match-only without master bank or cash ledger', function () {
    $masterExpense = Account::factory()->masterExpense()->withBalance(2_000)->create();
    $masterCash = Account::masterCash();
    $masterCash->update(['balance' => 10_000]);

    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        $masterExpense,
        350,
        'Vendor check',
    );

    $uncleared = $disbursement->bankTransaction;
    $expenseAfterDisburse = (float) $masterExpense->fresh()->balance;

    $statement = BankStatement::create([
        'filename' => 'expense-disburse-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Check paid',
        'amount' => -350,
        'status' => 'imported',
        'hash' => md5('expense-disburse-match-import'),
        'is_cleared' => false,
    ]);

    $masterBank = Account::masterBank();
    $bankBeforeMatch = (float) $masterBank->balance;

    $this->matching->clearMatchPair($uncleared, $imported);

    $imported = $imported->fresh();

    expect(BankTransactionWorkflow::canPostToCash($imported))->toBeFalse()
        ->and(BankTransactionWorkflow::canPostToMember($imported))->toBeFalse()
        ->and($imported->expense_disbursement_id)->toBe($disbursement->id)
        ->and($imported->status)->toBe('posted')
        ->and($imported->member_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and((float) $masterBank->fresh()->balance)->toBe($bankBeforeMatch)
        ->and((float) $masterCash->fresh()->balance)->toBe(10_000.0)
        ->and((float) $masterExpense->fresh()->balance)->toBe($expenseAfterDisburse)
        ->and($masterExpense->transactions()->count())->toBe(1);
});

test('synthetic operational statement lines are not match targets', function () {
    $statement = BankStatement::create([
        'filename' => 'import-cutoff-balances',
        'bank_name' => __('Import cut-off balances'),
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $placeholder = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Cutoff cash',
        'amount' => 500,
        'status' => 'posted',
        'hash' => md5('cutoff-placeholder'),
        'is_cleared' => false,
    ]);

    expect($this->matching->isImportedMatchCandidate($placeholder))->toBeFalse();
});

test('auto match selected reports ambiguous when multiple imported lines share amount', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-03',
        'name' => 'Ambiguous Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 1200, '2026-05-12');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'ambiguous.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    foreach (['a', 'b'] as $suffix) {
        BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => '2026-05-12',
            'description' => "Imported {$suffix}",
            'amount' => 1200,
            'status' => 'imported',
            'hash' => md5("ambiguous-{$suffix}"),
            'is_cleared' => true,
            'cleared_at' => now(),
        ]);
    }

    $stats = $this->matching->autoMatchSelected(Collection::make([$uncleared]));

    expect($stats['matched'])->toBe(0)
        ->and($stats['ambiguous'])->toBe(1)
        ->and($uncleared->fresh()->is_cleared)->toBeFalse();
});

test('clear without evidence marks synthetic operational row cleared', function () {
    $masterExpense = Account::factory()->masterExpense()->withBalance(2_000)->create();

    app(MasterExpenseDisbursementService::class)->disburse(
        $masterExpense,
        180,
        'Office supplies',
    );

    $operational = BankTransaction::query()
        ->where('expense_disbursement_id', '!=', null)
        ->where('is_cleared', false)
        ->first();

    $this->matching->clearWithoutEvidence($operational, 'Bank confirmed verbally');

    $operational = $operational->fresh();

    expect($operational->is_cleared)->toBeTrue()
        ->and($operational->cleared_at)->not->toBeNull()
        ->and($operational->description)->toContain('Bank confirmed verbally');
});

test('clear without evidence rejects imported bank file lines', function () {
    $statement = BankStatement::create([
        'filename' => 'reject-clear.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Imported only',
        'amount' => 500,
        'status' => 'imported',
        'hash' => md5('reject-clear-import'),
        'is_cleared' => false,
    ]);

    expect(fn () => $this->matching->clearWithoutEvidence($imported))
        ->toThrow(InvalidArgumentException::class);
});

test('find unique candidate returns the only imported match', function () {
    $member = Member::create([
        'member_number' => 'MEM-BM-UNQ',
        'name' => 'Unique Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->fundPostings->submit($member, 880, '2026-05-21');
    $this->fundPostings->accept($posting);

    $uncleared = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'unique-candidate.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-21',
        'description' => 'Unique import',
        'amount' => 880,
        'status' => 'imported',
        'hash' => md5('unique-candidate-import'),
        'is_cleared' => false,
    ]);

    $candidate = $this->matching->findUniqueCandidate($uncleared);

    expect($candidate?->is($imported))->toBeTrue()
        ->and($this->matching->autoMatchWhenUnique($uncleared))->toBeTrue()
        ->and($uncleared->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->fund_posting_id)->toBe($posting->id);
});
