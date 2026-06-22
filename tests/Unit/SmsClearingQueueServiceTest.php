<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\SmsClearingQueueService;
use App\Support\SmsClearing\SmsClearingQueueFilter;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->queue = app(SmsClearingQueueService::class);

    $this->admin = User::create([
        'name' => 'SMS Queue Admin',
        'email' => 'sms-queue-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->template = SmsImportTemplate::create([
        'name' => 'Queue Test Template',
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

    $this->session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $this->template->id,
        'imported_by' => $this->admin->id,
        'filename' => 'queue.csv',
        'file_path' => 'sms-imports/queue.csv',
        'status' => 'completed',
    ]);
});

test('open queue counts unmatched and ready rows separately', function () {
    $member = Member::factory()->create();

    SmsTransaction::create([
        'import_session_id' => $this->session->id,
        'bank_name' => 'SNB',
        'transaction_date' => now(),
        'amount' => 100,
        'transaction_type' => 'credit',
        'raw_sms' => 'matched row',
        'member_id' => $member->id,
        'is_duplicate' => false,
    ]);

    SmsTransaction::create([
        'import_session_id' => $this->session->id,
        'bank_name' => 'SNB',
        'transaction_date' => now(),
        'amount' => 50,
        'transaction_type' => 'credit',
        'raw_sms' => 'unmatched row',
        'member_id' => null,
        'is_duplicate' => false,
    ]);

    SmsTransaction::create([
        'import_session_id' => $this->session->id,
        'bank_name' => 'SNB',
        'transaction_date' => now(),
        'amount' => 25,
        'transaction_type' => 'credit',
        'raw_sms' => 'duplicate row',
        'is_duplicate' => true,
    ]);

    expect($this->queue->counts())->toBe([
        'unmatched' => 1,
        'ready_to_post' => 1,
        'all' => 2,
    ]);
});

test('open items query excludes posted and duplicate rows', function () {
    $member = Member::factory()->create();

    $open = SmsTransaction::create([
        'import_session_id' => $this->session->id,
        'bank_name' => 'SNB',
        'transaction_date' => now(),
        'amount' => 100,
        'transaction_type' => 'credit',
        'raw_sms' => 'open',
        'member_id' => $member->id,
        'is_duplicate' => false,
    ]);

    SmsTransaction::create([
        'import_session_id' => $this->session->id,
        'bank_name' => 'SNB',
        'transaction_date' => now(),
        'amount' => 100,
        'transaction_type' => 'credit',
        'raw_sms' => 'posted',
        'member_id' => $member->id,
        'posted_at' => now(),
        'is_duplicate' => false,
    ]);

    $ids = $this->queue->openItemsQuery(SmsClearingQueueFilter::All)->pluck('id')->all();

    expect($ids)->toBe([$open->id]);
});
