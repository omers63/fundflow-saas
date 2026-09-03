<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ReconciliationCorrectionService;
use App\Services\ReconciliationReportService;
use App\Services\ReconciliationService;
use App\Support\ContributionPolicySettings;
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

    $admin = User::create([
        'name' => 'SMS Bank Recon Admin',
        'email' => 'sms-bank-recon@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'SMS Bank Recon Template',
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
        'filename' => 'sms-bank-recon.csv',
        'file_path' => 'sms-imports/sms-bank-recon.csv',
        'status' => 'completed',
    ]);

    $this->member = Member::create([
        'member_number' => 'SMS-BANK-RECON-01',
        'name' => 'SMS Bank Recon Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->member);
});

function smsBankReconDefaults(): array
{
    return [
        'import_session_id' => test()->smsSession->id,
        'bank_name' => 'SNB',
    ];
}

function createSmsBankReconImport(float $amount, string $suffix): BankTransaction
{
    $statement = BankStatement::create([
        'filename' => "sms-bank-recon-{$suffix}.csv",
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    return BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->subDays(ContributionPolicySettings::stalePendingDays() + 2)->toDateString(),
        'description' => 'SMS bank recon import',
        'amount' => $amount,
        'status' => 'imported',
        'hash' => md5("sms-bank-recon-{$suffix}-{$amount}"),
        'is_cleared' => false,
        'created_at' => now()->subDays(ContributionPolicySettings::stalePendingDays() + 2),
        'updated_at' => now()->subDays(ContributionPolicySettings::stalePendingDays() + 2),
    ]);
}

test('nightly batch raises stale posted sms without bank link', function (): void {
    $staleDays = ContributionPolicySettings::stalePendingDays();

    $sms = SmsTransaction::create([
        ...smsBankReconDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->subDays($staleDays + 2)->toDateString(),
        'amount' => 420,
        'transaction_type' => 'credit',
        'reference' => 'SMS-NOBANK',
        'raw_sms' => 'Posted without bank',
        'posted_at' => now()->subDays($staleDays + 2),
        'is_duplicate' => false,
    ]);

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'SMS_RECON_UNMATCHED_BANK_LINE')
        ->open()
        ->where('affected_entities->sms_transaction_id', $sms->id)
        ->exists())->toBeTrue();
});

test('nightly batch raises ambiguous sms bank match', function (): void {
    $staleDays = ContributionPolicySettings::stalePendingDays();

    SmsTransaction::create([
        ...smsBankReconDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->subDays($staleDays + 2)->toDateString(),
        'amount' => 250,
        'transaction_type' => 'credit',
        'reference' => 'SMS-AMB',
        'raw_sms' => 'Ambiguous bank match',
        'posted_at' => now()->subDays($staleDays + 2),
        'is_duplicate' => false,
    ]);

    createSmsBankReconImport(250, 'a');
    createSmsBankReconImport(250, 'b');

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'SMS_RECON_AMBIGUOUS_BANK_MATCH')
        ->open()
        ->exists())->toBeTrue();
});

test('resolve sms bank match clears ambiguous reconciliation exception', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankReconDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 180,
        'transaction_type' => 'credit',
        'reference' => 'SMS-RESOLVE',
        'raw_sms' => 'Resolve me',
        'posted_at' => now()->subDays(10),
        'is_duplicate' => false,
    ]);

    $bankA = createSmsBankReconImport(180, 'resolve-a');
    createSmsBankReconImport(180, 'resolve-b');

    $exception = ReconciliationException::create([
        'exception_code' => 'SMS_RECON_AMBIGUOUS_BANK_MATCH',
        'domain' => 'sms_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
        'affected_entities' => [
            'sms_transaction_id' => $sms->id,
            'candidate_ids' => [$bankA->id],
        ],
    ]);

    app(ReconciliationCorrectionService::class)->resolveSmsBankMatch(
        $exception,
        $sms->id,
        $bankA->id,
        'Resolved during test',
    );

    expect($exception->fresh()->status)->toBe(ReconciliationException::STATUS_RESOLVED)
        ->and($sms->fresh()->is_bank_cleared)->toBeTrue()
        ->and($bankA->fresh()->sms_clearance_match_group_id)->not->toBeNull();
});

test('snapshot includes sms bank link integrity check', function (): void {
    $report = app(ReconciliationReportService::class)->buildReport(ReconciliationSnapshot::MODE_REALTIME);

    expect($report['checks'])->toHaveKey('sms_bank_link_integrity')
        ->and($report['checks']['sms_bank_link_integrity']['severity'])->toBe('ok');
});

test('snapshot flags stale posted sms without bank link', function (): void {
    $staleDays = ContributionPolicySettings::stalePendingDays();

    SmsTransaction::create([
        ...smsBankReconDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->subDays($staleDays + 2)->toDateString(),
        'amount' => 95,
        'transaction_type' => 'credit',
        'reference' => 'SMS-SNAP',
        'raw_sms' => 'Snapshot stale',
        'posted_at' => now()->subDays($staleDays + 2),
        'is_duplicate' => false,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(ReconciliationSnapshot::MODE_REALTIME);

    expect($report['checks']['sms_bank_link_integrity']['severity'])->toBe('critical')
        ->and($report['checks']['sms_bank_link_integrity']['issue_count'])->toBeGreaterThan(0);
});

test('admin auto resolve links unique sms bank pair', function (): void {
    $sms = SmsTransaction::create([
        ...smsBankReconDefaults(),
        'member_id' => $this->member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 275,
        'transaction_type' => 'credit',
        'reference' => 'SMS-AUTO',
        'raw_sms' => 'Auto resolve',
        'posted_at' => now()->subDays(10),
        'is_duplicate' => false,
    ]);

    $bank = createSmsBankReconImport(275, 'auto');

    $exception = ReconciliationException::create([
        'exception_code' => 'SMS_RECON_UNMATCHED_BANK_LINE',
        'domain' => 'sms_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
        'affected_entities' => [
            'sms_transaction_id' => $sms->id,
        ],
    ]);

    $resolved = app(ReconciliationService::class)->attemptAutoResolveForAdmin($exception);

    expect($resolved)->toBeTrue()
        ->and($exception->fresh()->status)->toBe(ReconciliationException::STATUS_RESOLVED)
        ->and($sms->fresh()->is_bank_cleared)->toBeTrue()
        ->and($bank->fresh()->sms_clearance_match_group_id)->toBe($sms->fresh()->sms_clearance_match_group_id);
});
