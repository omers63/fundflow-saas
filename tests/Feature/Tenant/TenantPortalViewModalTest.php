<?php

declare(strict_types=1);

use App\Filament\Tenant\Support\ViewBankStatementAction;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Filament\Tenant\Support\ViewContributionAction;
use App\Filament\Tenant\Support\ViewFundAuditLogAction;
use App\Filament\Tenant\Support\ViewMemberRequestAction;
use App\Filament\Tenant\Support\ViewNotificationLogAction;
use App\Filament\Tenant\Support\ViewSmsImportSessionAction;
use App\Filament\Tenant\Support\ViewSmsTransactionAction;
use App\Filament\Tenant\Support\ViewSupportRequestAction;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('notification log view modal sections include delivery and content', function () {
    $user = User::create([
        'name' => 'Recipient User',
        'email' => 'recipient@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $log = NotificationLog::create([
        'user_id' => $user->id,
        'channel' => 'mail',
        'subject' => 'Monthly statement ready',
        'body' => '<p>Your statement is ready.</p>',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $log->load('user');

    $sections = ViewNotificationLogAction::sections($log);

    expect($sections)->toHaveCount(4)
        ->and($sections[0]['hero']['label'])->toBe(__('Email'))
        ->and($sections[1]['title'])->toBe(__('Delivery'))
        ->and($sections[2]['items'][0]['value'])->toBe('Monthly statement ready')
        ->and($sections[3]['html'])->toContain('Your statement is ready.');
});

test('fund audit log view modal sections include payload', function () {
    $log = FundAuditLog::create([
        'event_type' => 'reconciliation.snapshot',
        'domain' => 'reconciliation',
        'payload' => ['status' => 'pass'],
        'checksum' => 'abc123',
        'occurred_at' => now(),
    ]);

    $sections = ViewFundAuditLogAction::sections($log);

    expect($sections)->toHaveCount(3)
        ->and($sections[0]['hero']['chip'])->toBe('reconciliation.snapshot')
        ->and($sections[1]['items'][1]['value'])->toBe(__('Reconciliation'))
        ->and($sections[2]['prose'])->toContain('"status": "pass"');
});

test('sms transaction view modal sections include raw sms and csv row', function () {
    $admin = User::create([
        'name' => 'SMS Admin',
        'email' => 'sms-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create(['name' => 'SMS Member']);

    $template = SmsImportTemplate::create([
        'name' => 'Modal Test Template',
        'bank_name' => 'Test Bank',
        'sms_column' => 'message',
        'has_header' => true,
        'delimiter' => ',',
        'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
        'is_default' => true,
    ]);

    $session = SmsImportSession::create([
        'filename' => 'alerts.csv',
        'bank_name' => 'Test Bank',
        'template_id' => $template->id,
        'status' => 'completed',
        'imported_by' => $admin->id,
        'file_path' => 'sms-imports/alerts.csv',
    ]);

    $transaction = SmsTransaction::create([
        'bank_name' => 'Test Bank',
        'import_session_id' => $session->id,
        'member_id' => $member->id,
        'transaction_date' => now(),
        'amount' => 150.50,
        'transaction_type' => 'credit',
        'reference' => 'REF-001',
        'raw_sms' => 'Credit SAR 150.50 received',
        'raw_data' => ['amount' => '150.50', 'type' => 'credit'],
        'is_duplicate' => false,
    ]);

    $transaction->load(['member', 'importSession']);

    $sections = ViewSmsTransactionAction::sections($transaction);

    expect($sections)->toHaveCount(4)
        ->and($sections[0]['hero']['type'])->toBe('credit')
        ->and($sections[1]['items'][5]['value'])->toBe('SMS Member')
        ->and($sections[2]['prose'])->toBe('Credit SAR 150.50 received')
        ->and($sections[3]['prose'])->toContain('amount: 150.50');
});

test('sms import session view modal sections include counts and error log', function () {
    $admin = User::create([
        'name' => 'Import Admin',
        'email' => 'import-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $template = SmsImportTemplate::create([
        'name' => 'Session Modal Template',
        'bank_name' => 'Test Bank',
        'sms_column' => 'message',
        'has_header' => true,
        'delimiter' => ',',
        'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
        'is_default' => true,
    ]);

    $session = SmsImportSession::create([
        'filename' => 'batch-june.csv',
        'bank_name' => 'Test Bank',
        'template_id' => $template->id,
        'status' => 'partially_completed',
        'imported_by' => $admin->id,
        'file_path' => 'sms-imports/batch-june.csv',
        'total_rows' => 10,
        'imported_count' => 8,
        'duplicate_count' => 1,
        'error_count' => 1,
        'notes' => 'One row failed validation.',
        'error_log' => ['row_5' => 'Invalid amount format'],
    ]);

    $session->load(['template', 'importer']);

    $sections = ViewSmsImportSessionAction::sections($session);

    expect($sections)->toHaveCount(4)
        ->and($sections[0]['hero']['label'])->toBe('batch-june.csv')
        ->and($sections[1]['items'][4]['value'])->toBe('10')
        ->and($sections[2]['prose'])->toBe('One row failed validation.')
        ->and($sections[3]['prose'])->toContain('Invalid amount format');
});

test('support request view modal sections include message body', function () {
    $user = User::create([
        'name' => 'Support User',
        'email' => 'support-user@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::factory()->create(['name' => 'Support Member', 'user_id' => $user->id]);

    $request = SupportRequest::create([
        'user_id' => $user->id,
        'member_id' => $member->id,
        'category' => SupportRequest::CATEGORY_LOAN_INQUIRY,
        'subject' => 'Loan balance question',
        'message' => 'What is my outstanding loan balance?',
    ]);

    $request->load(['user', 'member']);

    $sections = ViewSupportRequestAction::sections($request);

    expect($sections)->toHaveCount(3)
        ->and($sections[0]['hero']['label'])->toBe('Loan balance question')
        ->and($sections[2]['prose'])->toBe('What is my outstanding loan balance?');
});

test('member request view modal sections include payload', function () {
    $member = Member::factory()->create(['name' => 'Request Member']);

    $request = MemberRequest::create([
        'requester_member_id' => $member->id,
        'type' => MemberRequest::TYPE_ADD_DEPENDENT,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['dependent_name' => 'Child One'],
    ]);

    $request->load('requester');

    $sections = ViewMemberRequestAction::sections($request);

    expect($sections)->toHaveCount(4)
        ->and($sections[0]['hero']['chip'])->toBe(__('Pending'))
        ->and($sections[3]['prose'])->toContain('Child One');
});

test('bank statement view modal sections include import summary', function () {
    $statement = BankStatement::create([
        'filename' => 'june-statement.csv',
        'bank_name' => 'Test Bank',
        'statement_date' => now()->startOfMonth(),
        'total_rows' => 100,
        'imported_rows' => 95,
        'duplicate_rows' => 5,
        'status' => 'completed',
        'imported_at' => now(),
    ]);

    $sections = ViewBankStatementAction::sections($statement);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]['hero']['label'])->toBe('june-statement.csv')
        ->and($sections[1]['items'][1]['value'])->toBe('100');
});

test('bank transaction view modal sections include status chip', function () {
    $statement = BankStatement::create([
        'filename' => 'lines.csv',
        'status' => 'completed',
        'imported_at' => now(),
    ]);

    $transaction = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'amount' => 250.00,
        'transaction_type' => 'credit',
        'description' => 'Incoming transfer',
        'status' => 'imported',
        'reference' => 'REF-99',
        'hash' => md5('modal-test-txn'),
    ]);

    $transaction->load('bankStatement');

    $sections = ViewBankTransactionAction::sections($transaction);

    expect($sections)->toHaveCount(2)
        ->and($sections[0]['hero']['chip'])->toBe(__('Imported'))
        ->and($sections[1]['items'][2]['value'])->toBe('REF-99');
});

test('contribution view modal sections include settlement details', function () {
    $member = Member::factory()->create(['name' => 'Contrib Member']);

    $contribution = Contribution::factory()->create([
        'member_id' => $member->id,
        'status' => 'posted',
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
    ]);

    $contribution->load('member');

    $sections = ViewContributionAction::sections($contribution);

    expect($sections)->toHaveCount(3)
        ->and($sections[0]['hero']['subtitle'])->toBe('Contrib Member')
        ->and($sections[2]['title'])->toBe(__('Settlement'));
});
