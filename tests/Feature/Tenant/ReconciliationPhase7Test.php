<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankImportService;
use App\Services\EvidenceChannelMigrationService;
use App\Services\FundPostingService;
use App\Services\MemberCashOutService;
use App\Services\SmsOperationalClearingMatchService;
use App\Support\EvidenceChannelSettings;
use Illuminate\Http\UploadedFile;
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

    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_BANK_CSV);
});

test('evidence channel settings persist and expose channel helpers', function (): void {
    expect(EvidenceChannelSettings::usesBankCsv())->toBeTrue()
        ->and(EvidenceChannelSettings::usesSms())->toBeFalse()
        ->and(EvidenceChannelSettings::isSmsOnly())->toBeFalse();

    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_SMS);

    expect(EvidenceChannelSettings::channel())->toBe(EvidenceChannelSettings::CHANNEL_SMS)
        ->and(EvidenceChannelSettings::usesBankCsv())->toBeFalse()
        ->and(EvidenceChannelSettings::usesSms())->toBeTrue()
        ->and(EvidenceChannelSettings::isSmsOnly())->toBeTrue()
        ->and(Setting::get(EvidenceChannelSettings::GROUP, EvidenceChannelSettings::KEY))
        ->toBe(EvidenceChannelSettings::CHANNEL_SMS);
});

test('bank csv import is blocked when evidence channel is sms only', function (): void {
    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_SMS);

    $service = app(BankImportService::class);

    expect(fn () => $service->importCsv(
        file: UploadedFile::fake()->create('blocked.csv', 10, 'text/csv'),
    ))->toThrow(InvalidArgumentException::class);
});

test('sms ops match clears operational row and posts master bank without duplicate cash', function (): void {
    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_SMS);

    $member = Member::create([
        'member_number' => 'P7-OPS-01',
        'name' => 'Phase 7 Ops Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $admin = User::create([
        'name' => 'Phase 7 Admin',
        'email' => 'phase7-ops@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 7 SMS Template',
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
        'filename' => 'phase7-sms.csv',
        'file_path' => 'sms-imports/phase7-sms.csv',
        'status' => 'completed',
    ]);

    $sms = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 1000,
        'transaction_type' => 'credit',
        'reference' => 'P7-SMS',
        'raw_sms' => 'Credited SAR 1000',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($sms, $member): void {
        app(AccountingService::class)->postSmsTransactionToCash($sms, $member);
    });

    $posting = app(FundPostingService::class)->submit($member, 1000, now()->toDateString());
    app(FundPostingService::class)->accept($posting);
    $ops = $posting->bankTransaction->fresh();

    $cashBeforeMatch = (float) Account::query()->where('is_master', true)->where('type', 'cash')->value('balance');

    expect($ops)->not->toBeNull()
        ->and($ops->is_cleared)->toBeFalse();

    app(SmsOperationalClearingMatchService::class)->clearMatchPair($ops, $sms->fresh());

    $ops->refresh();
    $sms->refresh();

    $masterBankTxn = Transaction::query()->find($sms->master_bank_transaction_id);
    $masterBankAccount = Account::masterBank();

    expect($ops->is_cleared)->toBeTrue()
        ->and($ops->sms_ops_clearance_match_group_id)->not->toBeNull()
        ->and($sms->is_ops_cleared)->toBeTrue()
        ->and($sms->master_bank_transaction_id)->not->toBeNull()
        ->and($masterBankTxn)->not->toBeNull()
        ->and($masterBankTxn?->account_id)->toBe($masterBankAccount?->id)
        ->and((float) $masterBankTxn?->amount)->toBe(1000.0)
        ->and((float) $masterBankTxn?->balance_after)->toBe(1000.0)
        ->and((float) $masterBankAccount?->fresh()->balance)->toBe(1000.0)
        ->and((float) Account::query()->where('is_master', true)->where('type', 'cash')->value('balance'))
        ->toBe($cashBeforeMatch);
});

test('sms ops match clears cash-out operational row and debits master bank', function (): void {
    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_SMS);

    $member = Member::create([
        'member_number' => 'P7-OPS-OUT',
        'name' => 'Phase 7 Cash-Out Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    AccountingService::withoutMemberCashCollection(function () use ($member): void {
        app(AccountingService::class)->credit($member->cashAccount, 1000, 'Seed cash');
        app(AccountingService::class)->credit(Account::masterCash(), 1000, 'Seed master cash');
    });

    $admin = User::create([
        'name' => 'Phase 7 Cash-Out Admin',
        'email' => 'phase7-cashout@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Phase 7 Cash-Out SMS Template',
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
        'filename' => 'phase7-cashout-sms.csv',
        'file_path' => 'sms-imports/phase7-cashout-sms.csv',
        'status' => 'completed',
    ]);

    $cashOut = app(MemberCashOutService::class)->submit($member, 250);
    app(MemberCashOutService::class)->accept($cashOut);
    $ops = $cashOut->fresh()->bankTransaction;

    $sms = SmsTransaction::create([
        'import_session_id' => $session->id,
        'bank_name' => 'SNB',
        'member_id' => $member->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 250,
        'transaction_type' => 'debit',
        'reference' => 'P7-CASHOUT-SMS',
        'raw_sms' => 'Debited SAR 250',
        'is_duplicate' => false,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($sms, $member): void {
        app(AccountingService::class)->postSmsTransactionToCash($sms, $member);
    });

    $cashBeforeMatch = (float) Account::query()->where('is_master', true)->where('type', 'cash')->value('balance');

    expect($ops)->not->toBeNull()
        ->and((float) $ops->amount)->toBe(-250.0)
        ->and($ops->is_cleared)->toBeFalse();

    app(SmsOperationalClearingMatchService::class)->clearMatchPair($ops, $sms->fresh());

    $ops->refresh();
    $sms->refresh();

    $masterBankTxn = Transaction::query()->find($sms->master_bank_transaction_id);

    expect($ops->is_cleared)->toBeTrue()
        ->and($sms->is_ops_cleared)->toBeTrue()
        ->and($masterBankTxn)->not->toBeNull()
        ->and($masterBankTxn?->type)->toBe('debit')
        ->and((float) $masterBankTxn?->amount)->toBe(250.0)
        ->and((float) Account::query()->where('is_master', true)->where('type', 'cash')->value('balance'))
        ->toBe($cashBeforeMatch);
});

test('evidence channel migration analyzes backlog without applying changes', function (): void {
    EvidenceChannelSettings::save(EvidenceChannelSettings::CHANNEL_BANK_CSV);

    $report = app(EvidenceChannelMigrationService::class)->migrate(
        EvidenceChannelSettings::CHANNEL_SMS,
        dryRun: true,
    );

    expect($report['current_channel'])->toBe(EvidenceChannelSettings::CHANNEL_BANK_CSV)
        ->and($report['target_channel'])->toBe(EvidenceChannelSettings::CHANNEL_SMS)
        ->and($report['applied'])->toBeFalse();
});
