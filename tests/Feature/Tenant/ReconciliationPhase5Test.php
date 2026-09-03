<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankClearanceMatchGroup;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\SmsClearanceMatchGroup;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\FiscalClose\FiscalClosePurgeService;
use App\Services\FundPostingService;
use App\Services\ReconciliationReportService;
use App\Services\SmsBankClearingMatchService;
use App\Support\SystemLoggingSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    SmsTransaction::query()->forceDelete();
    BankTransaction::query()->delete();
    BankClearanceMatchGroup::query()->delete();
    SmsClearanceMatchGroup::query()->delete();
    FundAuditLog::query()->delete();

    SystemLoggingSettings::setFundAuditLogEnabled(true);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->bankMatching = app(BankClearingMatchService::class);
    $this->smsBankMatching = app(SmsBankClearingMatchService::class);
});

test('reconciliation snapshot pipeline includes sms and group match counts', function (): void {
    $member = Member::create([
        'member_number' => 'P5-SMS-01',
        'name' => 'Phase 5 SMS Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $admin = User::create([
        'name' => 'Phase 5 Admin',
        'email' => 'phase5-sms@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 5 SMS Template',
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
        'filename' => 'phase5-sms.csv',
        'file_path' => 'sms-imports/phase5-sms.csv',
        'status' => 'completed',
    ]);

    SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 250,
        'transaction_type' => 'credit',
        'reference' => 'P5-OPEN',
        'raw_sms' => 'Open SMS row',
        'is_duplicate' => false,
    ]);

    $postedSms = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 500,
        'transaction_type' => 'credit',
        'reference' => 'P5-POSTED',
        'raw_sms' => 'Posted SMS row',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($postedSms, $member): void {
        app(AccountingService::class)->postSmsTransactionToCash($postedSms, $member);
    });

    $bankStatement = BankStatement::create([
        'filename' => 'phase5-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bank = BankTransaction::create([
        'bank_statement_id' => $bankStatement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Phase 5 bank import',
        'amount' => 500,
        'status' => 'imported',
        'hash' => md5('phase5-bank-import'),
        'is_cleared' => false,
    ]);

    $this->smsBankMatching->clearMatchPair($postedSms->fresh(), $bank);

    $posting = app(FundPostingService::class)->submit($member, 1000, now()->toDateString());
    app(FundPostingService::class)->accept($posting);

    $ops = $posting->bankTransaction->fresh();
    $imported = BankTransaction::create([
        'bank_statement_id' => $bankStatement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Phase 5 ops match',
        'amount' => 1000,
        'status' => 'imported',
        'hash' => md5('phase5-ops-import'),
        'is_cleared' => false,
    ]);

    $this->bankMatching->clearMatchPair($ops, $imported);

    $report = app(ReconciliationReportService::class)->buildReport(ReconciliationSnapshot::MODE_REALTIME);

    expect($report['pipeline']['sms_unposted_count'])->toBe(1)
        ->and($report['pipeline']['sms_unmatched_bank_count'])->toBe(0)
        ->and($report['pipeline']['sms_bank_link_group_count'])->toBe(1)
        ->and($report['pipeline']['bank_clearance_group_count'])->toBe(0)
        ->and($report['checks']['sms_pipeline']['sms_bank_link_group_count'])->toBe(1);
});

test('bank clearance match writes fund audit log entries', function (): void {
    $member = Member::create([
        'member_number' => 'P5-BANK-01',
        'name' => 'Phase 5 Bank Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 750, now()->toDateString());
    app(FundPostingService::class)->accept($posting);

    $ops = $posting->bankTransaction->fresh();
    $statement = BankStatement::create([
        'filename' => 'phase5-audit.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Audit import',
        'amount' => 750,
        'status' => 'imported',
        'hash' => md5('phase5-audit-import'),
        'is_cleared' => false,
    ]);

    $this->bankMatching->clearMatchPair($ops, $imported);

    expect(FundAuditLog::query()->where('event_type', 'BANK_MATCH_LINKED')->exists())->toBeTrue();

    $this->bankMatching->unmatchClearedRow($imported->fresh());

    expect(FundAuditLog::query()->where('event_type', 'BANK_MATCH_UNMATCHED')->exists())->toBeTrue();
});

test('reverse sms post unmatches bank link first', function (): void {
    $member = Member::create([
        'member_number' => 'P5-REV-01',
        'name' => 'Phase 5 Reverse Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $admin = User::create([
        'name' => 'Phase 5 Reverse Admin',
        'email' => 'phase5-reverse@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 5 Reverse Template',
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
        'filename' => 'phase5-reverse.csv',
        'file_path' => 'sms-imports/phase5-reverse.csv',
        'status' => 'completed',
    ]);

    $sms = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 400,
        'transaction_type' => 'credit',
        'reference' => 'P5-REVERSE',
        'raw_sms' => 'Reverse me',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($sms, $member): void {
        app(AccountingService::class)->postSmsTransactionToCash($sms, $member);
    });

    $bank = BankTransaction::create([
        'bank_statement_id' => BankStatement::create([
            'filename' => 'phase5-reverse-bank.csv',
            'bank_name' => 'Test Bank',
            'status' => 'completed',
            'total_rows' => 1,
            'imported_rows' => 1,
            'duplicate_rows' => 0,
        ])->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Reverse bank import',
        'amount' => 400,
        'status' => 'imported',
        'hash' => md5('phase5-reverse-bank'),
        'is_cleared' => false,
    ]);

    $this->smsBankMatching->clearMatchPair($sms->fresh(), $bank);

    $this->accounting->reverseSmsTransactionPost($sms->fresh(), 'Phase 5 reverse test');

    expect($sms->fresh()->posted_at)->toBeNull()
        ->and($sms->fresh()->is_bank_cleared)->toBeFalse()
        ->and($sms->fresh()->sms_clearance_match_group_id)->toBeNull()
        ->and($bank->fresh()->sms_clearance_match_group_id)->toBeNull();
});

test('fiscal close tier a purge removes orphan clearance match groups', function (): void {
    $group = SmsClearanceMatchGroup::query()->create(['cleared_at' => now()]);
    $bankGroup = BankClearanceMatchGroup::query()->create(['cleared_at' => now()]);

    $deleted = app(FiscalClosePurgeService::class)->purgeOrphanClearanceMatchGroups();

    expect($deleted)->toBe(2)
        ->and(SmsClearanceMatchGroup::query()->count())->toBe(0)
        ->and(BankClearanceMatchGroup::query()->count())->toBe(0);
});
