<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\FundFlowService;
use App\Services\MemberInvariantService;
use App\Support\ContributionPolicySettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(FundFlowService::class);

    Account::query()->delete();
    Member::query()->delete();
    BankStatement::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

function createBankTransaction(array $overrides = []): BankTransaction
{
    static $counter = 0;
    $counter++;

    $statement = BankStatement::firstOrCreate(
        ['filename' => 'test.csv'],
        ['status' => 'completed', 'total_rows' => 0, 'imported_rows' => 0, 'duplicate_rows' => 0],
    );

    return BankTransaction::create(array_merge([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => "Test transaction {$counter}",
        'amount' => 5000,
        'reference' => "REF{$counter}",
        'status' => 'imported',
        'hash' => md5("test-{$counter}-".microtime()),
    ], $overrides));
}

test('member invariant includes direct bank import posted to member cash', function () {
    $member = Member::create([
        'member_number' => 'MEM-INV-01',
        'name' => 'Invariant Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction(['amount' => 5500, 'description' => 'Deposit']);

    $this->service->mirrorToCash([$txn->id]);
    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postToMember($txn->fresh(), $member),
    );

    $result = app(MemberInvariantService::class)->check($member->fresh());

    expect($result['cash_drift'])->toBeLessThanOrEqual(ContributionPolicySettings::reconTolerance())
        ->and($result['components']['direct_bank_imports_posted'])->toBe(5500.0);
});

test('post to member uses bank line detail when description is empty', function () {
    $member = Member::create([
        'member_number' => 'MEM-REF-01',
        'name' => 'Reference Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction([
        'amount' => 5500,
        'description' => '',
        'reference' => 'WIRE-5500',
        'status' => 'mirrored',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postToMember($txn, $member),
    );

    $ledger = Transaction::query()
        ->where('account_id', $member->cashAccount->id)
        ->where('type', 'credit')
        ->latest('id')
        ->first();

    expect($ledger)->not->toBeNull()
        ->and($ledger->description)->toContain('WIRE-5500')
        ->and($txn->fresh()->is_cleared)->toBeTrue();
});

test('post to member and mirror use the csv bank transaction date on ledger legs', function () {
    $member = Member::create([
        'member_number' => 'MEM-DATE-01',
        'name' => 'Csv Date Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $csvDate = '2025-03-18';
    $txn = createBankTransaction([
        'amount' => 2500,
        'description' => 'CSV deposit',
        'transaction_date' => $csvDate,
        'status' => 'imported',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->ensureMirroredAndPostToMember($txn->fresh(), $member),
    );

    $legs = Transaction::query()
        ->where('reference_type', BankTransaction::class)
        ->where('reference_id', $txn->id)
        ->get();

    expect($legs)->toHaveCount(3)
        ->and($legs->every(fn (Transaction $leg): bool => $leg->transacted_at->toDateString() === $csvDate))->toBeTrue()
        ->and($txn->fresh()->cleared_at->toDateString())->toBe($csvDate);
});

test('mirror to cash does not auto-reverse on unbalanced journal validation', function () {
    $txn = createBankTransaction(['amount' => 1500]);

    $this->service->mirrorToCash([$txn->id]);

    expect(Transaction::query()
        ->where('description', 'like', 'Reversal of #%')
        ->exists())->toBeFalse();

    expect(Transaction::query()
        ->where('reference_type', BankTransaction::class)
        ->where('reference_id', $txn->id)
        ->count())->toBe(2);

    expect(Account::masterBank()->balance)->toBe('1500.00')
        ->and(Account::masterCash()->balance)->toBe('1500.00');
});

test('mirror to cash updates master bank and master cash for credits', function () {
    $txn = createBankTransaction(['amount' => 5000]);

    $count = $this->service->mirrorToCash([$txn->id]);

    $txn->refresh();

    expect($count)->toBe(1);
    expect($txn->status)->toBe('mirrored');
    expect(Account::masterBank()->balance)->toBe('5000.00');
    expect(Account::masterCash()->balance)->toBe('5000.00');
    expect($txn->master_cash_transaction_id)->not->toBeNull();

    $ledger = Transaction::findOrFail($txn->master_cash_transaction_id);

    expect($ledger->account_id)->toBe(Account::masterCash()->id)
        ->and($ledger->reference_type)->toBe(BankTransaction::class)
        ->and($ledger->reference_id)->toBe($txn->id);

    $bankLedger = Transaction::query()
        ->where('reference_type', BankTransaction::class)
        ->where('reference_id', $txn->id)
        ->where('account_id', Account::masterBank()->id)
        ->first();

    expect($bankLedger)->not->toBeNull()
        ->and($bankLedger->type)->toBe('credit');
});

test('mirror to cash handles debits (negative amounts)', function () {
    $txn = createBankTransaction(['amount' => -10000]);

    $this->service->mirrorToCash([$txn->id]);

    expect($txn->fresh()->status)->toBe('mirrored');
    expect(Account::masterBank()->balance)->toBe('-10000.00');
    expect(Account::masterCash()->balance)->toBe('-10000.00');
});

test('mirror to cash only processes imported transactions', function () {
    $imported = createBankTransaction(['amount' => 3000]);
    $mirrored = createBankTransaction(['amount' => 2000, 'status' => 'mirrored']);

    $count = $this->service->mirrorToCash([$imported->id, $mirrored->id]);

    expect($count)->toBe(1);
    expect(Account::masterCash()->balance)->toBe('3000.00');
});

test('mirror multiple transactions at once', function () {
    $txn1 = createBankTransaction(['amount' => 5000]);
    $txn2 = createBankTransaction(['amount' => 3000]);
    $txn3 = createBankTransaction(['amount' => -1000]);

    $count = $this->service->mirrorToCash([$txn1->id, $txn2->id, $txn3->id]);

    expect($count)->toBe(3);
    expect(Account::masterCash()->balance)->toBe('7000.00');
    expect(Account::masterBank()->balance)->toBe('7000.00');
});

test('ensure mirrored and post to member mirrors imported lines first', function () {
    $member = Member::create([
        'member_number' => 'MEM-EM-01',
        'name' => 'Ensure Mirror',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction(['amount' => 2500, 'status' => 'imported']);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->ensureMirroredAndPostToMember($txn, $member),
    );

    $txn->refresh();

    expect($txn->status)->toBe('posted')
        ->and($txn->member_id)->toBe($member->id)
        ->and($member->cashAccount->fresh()->balance)->toBe('2500.00')
        ->and(Account::masterCash()->balance)->toBe('2500.00');
});

test('ensure mirrored and post to member does not raise realtime pool or member cash drift', function () {
    ReconciliationException::query()->delete();

    $member = Member::create([
        'member_number' => 'MEM-NO-DRIFT',
        'name' => 'No Drift Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction(['amount' => 3, 'status' => 'imported', 'description' => 'Small deposit']);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->ensureMirroredAndPostToMember($txn, $member),
    );

    expect(ReconciliationException::query()
        ->whereIn('exception_code', ['MASTER_CASH_POOL_DRIFT', 'MEMBER_CASH_DRIFT'])
        ->open()
        ->exists())->toBeFalse()
        ->and($txn->fresh()->status)->toBe('posted')
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(3.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(3.0);
});

test('post to member credits member cash account', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction(['amount' => 5000, 'status' => 'mirrored']);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postToMember($txn, $member),
    );

    expect($txn->fresh()->status)->toBe('posted');
    expect($txn->fresh()->member_id)->toBe($member->id);
    expect($member->cashAccount->fresh()->balance)->toBe('5000.00');
});

test('post debit to member debits member cash account', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 10000]);

    $txn = createBankTransaction(['amount' => -3000, 'status' => 'mirrored']);

    $this->service->postToMember($txn, $member);

    expect($member->cashAccount->fresh()->balance)->toBe('7000.00');
});

test('suggest member matches finds member by member number', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $txn = createBankTransaction([
        'description' => 'Transfer from MEM-0001',
        'reference' => 'Contribution',
    ]);

    $suggestions = $this->service->suggestMemberMatches(collect([$txn]));

    expect($suggestions[0]['member_id'])->toBe($member->id);
    expect($suggestions[0]['confidence'])->toBe('high');
});

test('suggest member matches finds member by name', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $txn = createBankTransaction([
        'description' => 'Deposit from John Doe',
        'reference' => '',
    ]);

    $suggestions = $this->service->suggestMemberMatches(collect([$txn]));

    expect($suggestions[0]['member_id'])->toBe($member->id);
    expect($suggestions[0]['confidence'])->toBe('medium');
});

test('full fund flow: import, mirror, post, contribute', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $txn = createBankTransaction(['amount' => 5000]);

    $this->service->mirrorToCash([$txn->id]);
    expect(Account::masterBank()->balance)->toBe('5000.00');
    expect(Account::masterCash()->balance)->toBe('5000.00');

    $txn->refresh();
    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postToMember($txn, $member),
    );
    expect($member->cashAccount->fresh()->balance)->toBe('5000.00');

    $contributionService = app(ContributionService::class);
    $contribution = $contributionService->recordContribution($member, now()->startOfMonth()->format('Y-m-d'));
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);
    $contributionService->postContribution($contribution);

    expect($member->cashAccount->fresh()->balance)->toBe('0.00');
    expect($member->fundAccount->fresh()->balance)->toBe('5000.00');
    expect(Account::masterFund()->balance)->toBe('5000.00');
});
