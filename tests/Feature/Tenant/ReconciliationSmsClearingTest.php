<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
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
    ReconciliationException::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $admin = User::create([
        'name' => 'SMS Recon Admin',
        'email' => 'sms-recon-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'SMS Recon Template',
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
        'filename' => 'recon.csv',
        'file_path' => 'sms-imports/recon.csv',
        'status' => 'completed',
    ]);

    $this->accounting = app(AccountingService::class);
});

function smsTransactionDefaults(): array
{
    return [
        'import_session_id' => test()->smsSession->id,
        'bank_name' => 'SNB',
    ];
}

test('snapshot sms pipeline reports open queue counts', function (): void {
    SmsTransaction::create([
        ...smsTransactionDefaults(),
        'transaction_date' => now()->toDateString(),
        'amount' => 150,
        'transaction_type' => 'credit',
        'reference' => 'SMS-PIPE-1',
        'raw_sms' => 'Member credited SAR 150',
        'is_duplicate' => false,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(ReconciliationSnapshot::MODE_REALTIME);

    expect($report['pipeline']['sms_unposted_count'])->toBe(1)
        ->and($report['pipeline']['sms_unposted_amount'])->toBe(150.0)
        ->and($report['checks']['sms_pipeline']['severity'])->toBe('warning')
        ->and($report['checks']['sms_transaction_posting_integrity']['severity'])->toBe('ok');
});

test('snapshot flags posted sms rows missing ledger legs', function (): void {
    $member = Member::create([
        'member_number' => 'SMS-RECON-01',
        'name' => 'SMS Recon Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    SmsTransaction::create([
        ...smsTransactionDefaults(),
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 200,
        'transaction_type' => 'credit',
        'reference' => 'SMS-BROKEN',
        'raw_sms' => 'Broken posted row',
        'posted_at' => now(),
        'is_duplicate' => false,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(ReconciliationSnapshot::MODE_REALTIME);

    expect($report['checks']['sms_transaction_posting_integrity']['severity'])->toBe('critical')
        ->and($report['checks']['sms_transaction_posting_integrity']['issue_count'])->toBeGreaterThan(0);
});

test('nightly batch raises sms member unmatched for stale unposted rows', function (): void {
    $staleDays = ContributionPolicySettings::stalePendingDays();

    SmsTransaction::create([
        ...smsTransactionDefaults(),
        'transaction_date' => now()->subDays($staleDays + 1)->toDateString(),
        'amount' => 300,
        'transaction_type' => 'credit',
        'reference' => 'SMS-STALE',
        'raw_sms' => 'Stale unmatched SMS',
        'is_duplicate' => false,
        'created_at' => now()->subDays($staleDays + 2),
        'updated_at' => now()->subDays($staleDays + 2),
    ]);

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'SMS_MEMBER_UNMATCHED')
        ->open()
        ->exists())->toBeTrue();
});

test('assign member and post sms resolves reconciliation exception', function (): void {
    $member = Member::create([
        'member_number' => 'SMS-RECON-02',
        'name' => 'Assign Post Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $tx = SmsTransaction::create([
        ...smsTransactionDefaults(),
        'transaction_date' => now()->toDateString(),
        'amount' => 125,
        'transaction_type' => 'credit',
        'reference' => 'SMS-ASSIGN',
        'raw_sms' => 'Assign and post me',
        'is_duplicate' => false,
    ]);

    $exception = ReconciliationException::create([
        'exception_code' => 'SMS_MEMBER_UNMATCHED',
        'domain' => 'sms_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
        'affected_entities' => [
            'sms_transaction_id' => $tx->id,
        ],
    ]);

    app(ReconciliationCorrectionService::class)->assignMemberAndPostSms(
        $exception,
        $tx->id,
        $member->id,
        'Assigned during reconciliation',
    );

    $tx = $tx->fresh();

    expect($exception->fresh()->status)->toBe(ReconciliationException::STATUS_RESOLVED)
        ->and($tx->posted_at)->not->toBeNull()
        ->and($tx->member_id)->toBe($member->id)
        ->and(Transaction::query()->where('reference_type', SmsTransaction::class)->where('reference_id', $tx->id)->count())
        ->toBe(2);
});

test('reverse sms post clears posted state and reverses ledger legs', function (): void {
    $member = Member::create([
        'member_number' => 'SMS-RECON-03',
        'name' => 'Reverse SMS Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $tx = SmsTransaction::create([
        ...smsTransactionDefaults(),
        'transaction_date' => now()->toDateString(),
        'amount' => 90,
        'transaction_type' => 'credit',
        'reference' => 'SMS-REV',
        'raw_sms' => 'Reverse me',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($member, $tx): void {
        $this->accounting->postSmsTransactionToCash($tx, $member);
    });

    $memberLeg = Transaction::query()
        ->where('reference_type', SmsTransaction::class)
        ->where('reference_id', $tx->id)
        ->whereHas(
            'account',
            fn ($query) => $query->where('type', 'cash')->where('is_master', false),
        )
        ->first();

    AccountingService::withoutMemberCashCollection(function () use ($tx): void {
        $this->accounting->reverseSmsTransactionPost($tx->fresh(), 'Test reverse');
    });

    expect($tx->fresh()->posted_at)->toBeNull()
        ->and($memberLeg)->not->toBeNull()
        ->and($this->accounting->hasExistingReversal($memberLeg))->toBeTrue()
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0);
});
