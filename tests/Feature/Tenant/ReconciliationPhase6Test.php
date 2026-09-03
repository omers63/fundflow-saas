<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\FundPostingService;
use App\Services\ReconciliationService;
use App\Services\SmsBankClearingMatchService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    SmsTransaction::query()->forceDelete();
    BankTransaction::query()->delete();
    ReconciliationException::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->bankMatching = app(BankClearingMatchService::class);
    $this->smsMatching = app(SmsBankClearingMatchService::class);
    $this->fundPostings = app(FundPostingService::class);
});

test('bank clear match group supports two operational rows to two imported lines', function (): void {
    $member = Member::create([
        'member_number' => 'P6-BANK-01',
        'name' => 'Phase 6 Bank Member A',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $postingA = $this->fundPostings->submit($member, 300, '2026-07-01');
    $this->fundPostings->accept($postingA);
    $postingB = $this->fundPostings->submit($member, 200, '2026-07-01');
    $this->fundPostings->accept($postingB);

    $operational = collect([
        $postingA->bankTransaction->fresh(),
        $postingB->bankTransaction->fresh(),
    ]);

    $statement = BankStatement::create([
        'filename' => 'phase6-nm-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    $imported = collect([250, 250])->map(function (int $amount, int $index) use ($statement) {
        return BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => '2026-07-01',
            'description' => "Phase 6 import {$index}",
            'amount' => $amount,
            'status' => 'imported',
            'hash' => md5("phase6-nm-bank-{$index}-{$amount}"),
            'is_cleared' => false,
        ]);
    });

    $this->bankMatching->clearMatchGroup($operational, $imported);

    $groupId = $operational->first()->fresh()->bank_clearance_match_group_id;

    expect($groupId)->not->toBeNull()
        ->and($operational->every(fn (BankTransaction $line): bool => $line->fresh()->is_cleared))->toBeTrue()
        ->and($imported->every(fn (BankTransaction $line): bool => $line->fresh()->is_cleared))->toBeTrue()
        ->and($operational->every(fn (BankTransaction $line): bool => (int) $line->fresh()->bank_clearance_match_group_id === (int) $groupId))->toBeTrue()
        ->and($imported->every(fn (BankTransaction $line): bool => (int) $line->fresh()->bank_clearance_match_group_id === (int) $groupId))->toBeTrue();
});

test('sms clear match group supports two sms rows to two bank imports', function (): void {
    $member = Member::create([
        'member_number' => 'P6-SMS-01',
        'name' => 'Phase 6 SMS Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $admin = User::create([
        'name' => 'Phase 6 SMS Admin',
        'email' => 'phase6-sms@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 6 SMS Template',
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

    $session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $template->id,
        'imported_by' => $admin->id,
        'filename' => 'phase6-sms.csv',
        'file_path' => 'sms-imports/phase6-sms.csv',
        'status' => 'completed',
    ]);

    $smsA = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 150,
        'transaction_type' => 'credit',
        'reference' => 'P6-SMS-A',
        'raw_sms' => 'SMS A',
        'is_duplicate' => false,
    ]);

    $smsB = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 350,
        'transaction_type' => 'credit',
        'reference' => 'P6-SMS-B',
        'raw_sms' => 'SMS B',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($smsA, $smsB, $member): void {
        app(AccountingService::class)->postSmsTransactionToCash($smsA, $member);
        app(AccountingService::class)->postSmsTransactionToCash($smsB, $member);
    });

    $statement = BankStatement::create([
        'filename' => 'phase6-sms-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    $bankA = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Phase 6 bank A',
        'amount' => 200,
        'status' => 'imported',
        'hash' => md5('phase6-bank-a'),
        'is_cleared' => false,
    ]);

    $bankB = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Phase 6 bank B',
        'amount' => 300,
        'status' => 'imported',
        'hash' => md5('phase6-bank-b'),
        'is_cleared' => false,
    ]);

    $cashBefore = (float) $member->cashAccount->fresh()->balance;

    $this->smsMatching->clearMatchGroup(collect([$smsA->fresh(), $smsB->fresh()]), collect([$bankA, $bankB]));

    $groupId = $smsA->fresh()->sms_clearance_match_group_id;

    expect($groupId)->not->toBeNull()
        ->and($smsA->fresh()->is_bank_cleared)->toBeTrue()
        ->and($smsB->fresh()->is_bank_cleared)->toBeTrue()
        ->and($bankA->fresh()->sms_clearance_match_group_id)->toBe($groupId)
        ->and($bankB->fresh()->sms_clearance_match_group_id)->toBe($groupId)
        ->and((float) $member->cashAccount->fresh()->balance)->toBe($cashBefore);

    $this->smsMatching->unmatchClearedGroup($smsA->fresh());

    expect($smsA->fresh()->is_bank_cleared)->toBeFalse()
        ->and($smsB->fresh()->is_bank_cleared)->toBeFalse()
        ->and($bankA->fresh()->sms_clearance_match_group_id)->toBeNull();
});

test('scan group match hints detects two by two sms and bank subsets', function (): void {
    $member = Member::create([
        'member_number' => 'P6-HINT-01',
        'name' => 'Phase 6 Hint Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $admin = User::create([
        'name' => 'Phase 6 Hint Admin',
        'email' => 'phase6-hint@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 6 Hint Template',
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

    $session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $template->id,
        'imported_by' => $admin->id,
        'filename' => 'phase6-hint.csv',
        'file_path' => 'sms-imports/phase6-hint.csv',
        'status' => 'completed',
    ]);

    SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 200,
        'transaction_type' => 'credit',
        'reference' => 'P6-HINT-A',
        'raw_sms' => 'Hint A',
        'is_duplicate' => false,
        'posted_at' => now(),
    ]);

    SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 300,
        'transaction_type' => 'credit',
        'reference' => 'P6-HINT-B',
        'raw_sms' => 'Hint B',
        'is_duplicate' => false,
        'posted_at' => now(),
    ]);

    $statement = BankStatement::create([
        'filename' => 'phase6-hint-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Hint bank A',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('phase6-hint-bank-a'),
        'is_cleared' => false,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Hint bank B',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('phase6-hint-bank-b'),
        'is_cleared' => false,
    ]);

    $hints = $this->smsMatching->scanGroupMatchHints();

    expect($hints['many_to_many'])->not->toBeEmpty();
});

test('nightly batch raises many to many sms splittable hint', function (): void {
    $member = Member::create([
        'member_number' => 'P6-RECON-01',
        'name' => 'Phase 6 Recon Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $admin = User::create([
        'name' => 'Phase 6 Recon Admin',
        'email' => 'phase6-recon@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 6 Recon Template',
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

    $session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $template->id,
        'imported_by' => $admin->id,
        'filename' => 'phase6-recon.csv',
        'file_path' => 'sms-imports/phase6-recon.csv',
        'status' => 'completed',
    ]);

    SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->subDays(30)->toDateString(),
        'amount' => 100,
        'transaction_type' => 'credit',
        'reference' => 'P6-RECON-A',
        'raw_sms' => 'Recon A',
        'is_duplicate' => false,
        'posted_at' => now()->subDays(30),
    ]);

    SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->subDays(30)->toDateString(),
        'amount' => 400,
        'transaction_type' => 'credit',
        'reference' => 'P6-RECON-B',
        'raw_sms' => 'Recon B',
        'is_duplicate' => false,
        'posted_at' => now()->subDays(30),
    ]);

    $statement = BankStatement::create([
        'filename' => 'phase6-recon-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->subDays(30)->toDateString(),
        'description' => 'Recon bank A',
        'amount' => 150,
        'status' => 'imported',
        'hash' => md5('phase6-recon-bank-a'),
        'is_cleared' => false,
        'created_at' => now()->subDays(30),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->subDays(30)->toDateString(),
        'description' => 'Recon bank B',
        'amount' => 350,
        'status' => 'imported',
        'hash' => md5('phase6-recon-bank-b'),
        'is_cleared' => false,
        'created_at' => now()->subDays(30),
    ]);

    app(ReconciliationService::class)->runNightlyBatch();

    expect(
        ReconciliationException::query()
            ->where('exception_code', 'SMS_RECON_SPLITTABLE_BANK_MATCH')
            ->where('affected_entities->direction', 'many_to_many')
            ->exists(),
    )->toBeTrue();
});
