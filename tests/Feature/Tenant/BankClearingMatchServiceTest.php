<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\FundPostingService;
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

    $this->matching->clearMatchPair($uncleared, $imported);

    expect($uncleared->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->fund_posting_id)->toBe($posting->id);
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
