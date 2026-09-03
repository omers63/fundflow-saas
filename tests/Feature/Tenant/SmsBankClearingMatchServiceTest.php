<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\SmsBankClearingMatchService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    SmsTransaction::query()->forceDelete();
    BankTransaction::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $admin = User::create([
        'name' => 'SMS Bank Match Admin',
        'email' => 'sms-bank-match@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'SMS Bank Match Template',
        'bank_name' => 'SNB',
        'sms_column' => 'message',
        'has_header' => true,
        'delimiter' => ',',
        'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
        'member_match_pattern' => '/Member[:\s]+(?P<member>M\d+)/',
        'member_match_field' => 'member_number',
        'credit_keywords' => ['credited'],
        'debit_keywords' => ['debited'],
        'is_default' => true,
    ]);

    $this->smsSession = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $template->id,
        'imported_by' => $admin->id,
        'filename' => 'sms-bank-match.csv',
        'file_path' => 'sms-imports/sms-bank-match.csv',
        'status' => 'completed',
    ]);

    $this->member = Member::create([
        'member_number' => 'SMS-BANK-01',
        'name' => 'SMS Bank Match Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->matching = app(SmsBankClearingMatchService::class);
    $this->accounting = app(AccountingService::class);
});

function smsBankMatchDefaults(): array
{
    return [
        'import_session_id' => test()->smsSession->id,
        'bank_name' => 'SNB',
    ];
}

function createBankImport(float $amount, string $suffix = 'a'): BankTransaction
{
    $statement = BankStatement::create([
        'filename' => "sms-bank-match-{$suffix}.csv",
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    return BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'SMS bank match import',
        'amount' => $amount,
        'status' => 'imported',
        'hash' => md5("sms-bank-match-{$suffix}-{$amount}"),
        'is_cleared' => false,
    ]);
}

test('clear match pair links sms and bank without duplicate cash credit', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 500,
        'transaction_type' => 'credit',
        'reference' => 'SMS-PAIR',
        'raw_sms' => 'Member credited SAR 500',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($sms): void {
        $this->accounting->postSmsTransactionToCash($sms, $this->member);
    });

    $bank = createBankImport(500, 'pair');
    $cashBefore = (float) $this->member->cashAccount->fresh()->balance;

    $this->matching->clearMatchPair($sms->fresh(), $bank);

    expect($sms->fresh()->is_bank_cleared)->toBeTrue()
        ->and($sms->fresh()->sms_clearance_match_group_id)->not->toBeNull()
        ->and($bank->fresh()->sms_clearance_match_group_id)->toBe($sms->fresh()->sms_clearance_match_group_id)
        ->and((float) $this->member->cashAccount->fresh()->balance)->toBe($cashBefore)
        ->and(Transaction::query()->where('reference_type', SmsTransaction::class)->where('reference_id', $sms->id)->count())->toBe(2)
        ->and($bank->fresh()->master_bank_transaction_id)->not->toBeNull();
});

test('clear match pair blocks unposted sms without member', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'transaction_date' => now()->toDateString(),
        'amount' => 100,
        'transaction_type' => 'credit',
        'reference' => 'SMS-NOMEM',
        'raw_sms' => 'No member',
        'is_duplicate' => false,
    ]);

    $bank = createBankImport(100, 'nomem');

    expect(fn () => $this->matching->clearMatchPair($sms, $bank))
        ->toThrow(InvalidArgumentException::class);
});

test('clear match group supports one sms to many bank lines', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 300,
        'transaction_type' => 'credit',
        'reference' => 'SMS-1N',
        'raw_sms' => 'Split bank evidence',
        'is_duplicate' => false,
    ]);

    $bankA = createBankImport(100, '1n-a');
    $bankB = createBankImport(200, '1n-b');

    $this->matching->clearMatchGroup(collect([$sms]), collect([$bankA, $bankB]));

    $groupId = $sms->fresh()->sms_clearance_match_group_id;

    expect($groupId)->not->toBeNull()
        ->and($bankA->fresh()->sms_clearance_match_group_id)->toBe($groupId)
        ->and($bankB->fresh()->sms_clearance_match_group_id)->toBe($groupId)
        ->and($sms->fresh()->is_bank_cleared)->toBeTrue();
});

test('clear match group supports many sms to one bank line', function (): void {
    $smsA = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 120,
        'transaction_type' => 'credit',
        'reference' => 'SMS-N1-A',
        'raw_sms' => 'Part A',
        'is_duplicate' => false,
    ]);

    $smsB = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 80,
        'transaction_type' => 'credit',
        'reference' => 'SMS-N1-B',
        'raw_sms' => 'Part B',
        'is_duplicate' => false,
    ]);

    $bank = createBankImport(200, 'n1');

    $this->matching->clearMatchGroup(collect([$smsA, $smsB]), collect([$bank]));

    $groupId = $bank->fresh()->sms_clearance_match_group_id;

    expect($groupId)->not->toBeNull()
        ->and($smsA->fresh()->sms_clearance_match_group_id)->toBe($groupId)
        ->and($smsB->fresh()->sms_clearance_match_group_id)->toBe($groupId);
});

test('unmatch cleared group restores both sides', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 250,
        'transaction_type' => 'credit',
        'reference' => 'SMS-UNMATCH',
        'raw_sms' => 'Unmatch me',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($sms): void {
        $this->accounting->postSmsTransactionToCash($sms, $this->member);
    });

    $bank = createBankImport(250, 'unmatch');

    $this->matching->clearMatchPair($sms->fresh(), $bank);
    $this->matching->unmatchClearedGroup($sms->fresh());

    expect($sms->fresh()->is_bank_cleared)->toBeFalse()
        ->and($sms->fresh()->sms_clearance_match_group_id)->toBeNull()
        ->and($bank->fresh()->sms_clearance_match_group_id)->toBeNull()
        ->and($bank->fresh()->master_bank_transaction_id)->toBeNull();
});

test('group match rejects unbalanced amounts', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 500,
        'transaction_type' => 'credit',
        'reference' => 'SMS-BAD-SUM',
        'raw_sms' => 'Bad sum',
        'is_duplicate' => false,
    ]);

    $bankA = createBankImport(100, 'bad-a');
    $bankB = createBankImport(200, 'bad-b');

    expect(fn () => $this->matching->clearMatchGroup(collect([$sms]), collect([$bankA, $bankB])))
        ->toThrow(InvalidArgumentException::class);
});

test('scan group match hints finds one to many subset', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankMatchDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 450,
        'transaction_type' => 'credit',
        'reference' => 'SMS-HINT',
        'raw_sms' => 'Hint scan',
        'is_duplicate' => false,
    ]);

    createBankImport(150, 'hint-a');
    createBankImport(300, 'hint-b');
    createBankImport(999, 'hint-noise');

    $hints = $this->matching->scanGroupMatchHints();

    expect(collect($hints['one_to_many'])->contains(
        fn (array $hint): bool => $hint['sms_transaction_id'] === $sms->id
            && count($hint['bank_transaction_ids']) >= 2,
    ))->toBeTrue();
});
