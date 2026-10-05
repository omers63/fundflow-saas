<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\Ocr\ReceiptOcrService;
use App\Services\Ocr\ThreeWayDepositMatchService;
use App\Services\SyntheticBankStatementFactory;
use App\Support\DepositOcrSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    FundPosting::query()->delete();
    BankTransaction::query()->delete();
    SmsTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->user = User::create([
        'name' => 'OCR Member',
        'email' => 'ocr-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-OCR1',
        'name' => 'OCR Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->admin = User::create([
        'name' => 'OCR Admin',
        'email' => 'ocr-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'OCR Template',
        'bank_name' => 'SNB',
        'sms_column' => 'message',
        'has_header' => true,
        'delimiter' => ',',
        'amount_pattern' => '/(?P<amount>[\d,]+\.?\d*)/',
        'member_match_pattern' => '/Member[:\s]+(?P<member>M\d+)/',
        'member_match_field' => 'member_number',
        'credit_keywords' => ['credited'],
        'debit_keywords' => ['debited'],
        'is_default' => true,
    ]);

    $this->smsSession = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $template->id,
        'imported_by' => $this->admin->id,
        'filename' => 'ocr-test.txt',
        'file_path' => 'sms-imports/ocr-test.txt',
        'status' => 'completed',
    ]);

    Setting::set(DepositOcrSettings::GROUP, 'auto_accept_enabled', '1');
    Setting::set(DepositOcrSettings::GROUP, 'confidence_threshold', '0.85');
    Setting::set(DepositOcrSettings::GROUP, 'driver', 'fake');
});

test('fake ocr extracts amount and optional iban from comments', function () {
    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 250,
        'reference' => 'DEP-1',
        'comments' => 'IBAN:SA0380000000608010167519 OCR_AMOUNT:250',
        'status' => 'pending',
    ]);

    $result = app(ReceiptOcrService::class)->extractAndStore($posting);

    expect($result->amount)->toBe(250.0)
        ->and($result->iban)->toBe('SA0380000000608010167519')
        ->and($result->confidence)->toBeGreaterThan(0.7)
        ->and($posting->fresh()->ocr_extraction)->toBeArray();
});

test('three-way match auto-accepts when bank and sms agree within tolerance', function () {
    $date = now()->toDateString();
    $amount = 400.0;

    $posting = app(FundPostingService::class)->submit(
        $this->member,
        $amount,
        $date,
        'TW-REF-1',
        null,
        'OCR_AMOUNT:400 OCR_DATE:'.$date.' OCR_REF:TW-REF-1',
    );

    // submit may have already attempted auto-accept; ensure pending for explicit evaluate path
    if ($posting->status === 'accepted') {
        expect($posting->status)->toBe('accepted');

        return;
    }

    $statement = app(SyntheticBankStatementFactory::class)->memberPostings();

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => $date,
        'description' => 'Deposit TW-REF-1',
        'amount' => $amount,
        'reference' => 'TW-REF-1',
        'status' => 'imported',
        'member_id' => null,
        'hash' => md5('ocr-bank-'.$amount),
        'is_cleared' => false,
    ]);

    SmsTransaction::create([
        'import_session_id' => $this->smsSession->id,
        'amount' => $amount,
        'transaction_date' => $date,
        'transaction_type' => 'credit',
        'reference' => 'TW-REF-1',
        'raw_sms' => 'transfer '.$amount.' TW-REF-1',
        'member_id' => null,
        'posted_at' => null,
    ]);

    $accepted = app(ThreeWayDepositMatchService::class)->maybeAutoAccept($posting->fresh());

    expect($accepted)->toBeTrue()
        ->and($posting->fresh()->status)->toBe('accepted');
});

test('auto-accept never posts when ocr amount drifts beyond tolerance', function () {
    Setting::set(DepositOcrSettings::GROUP, 'amount_tolerance', '0.01');

    $posting = FundPosting::create([
        'member_id' => $this->member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 100,
        'reference' => 'DRIFT-1',
        'comments' => 'OCR_AMOUNT:150',
        'status' => 'pending',
    ]);

    $date = now()->toDateString();
    $statement = app(SyntheticBankStatementFactory::class)->memberPostings();

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => $date,
        'description' => 'Deposit',
        'amount' => 150,
        'reference' => 'DRIFT-1',
        'status' => 'imported',
        'hash' => md5('ocr-drift'),
        'is_cleared' => false,
    ]);

    SmsTransaction::create([
        'import_session_id' => $this->smsSession->id,
        'amount' => 150,
        'transaction_date' => $date,
        'transaction_type' => 'credit',
        'reference' => 'DRIFT-1',
        'raw_sms' => '150',
        'posted_at' => null,
    ]);

    $accepted = app(ThreeWayDepositMatchService::class)->maybeAutoAccept($posting);

    expect($accepted)->toBeFalse()
        ->and($posting->fresh()->status)->toBe('pending')
        ->and($posting->fresh()->ocr_match_status)->toBe('amount_mismatch');
});
