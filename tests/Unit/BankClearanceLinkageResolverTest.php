<?php

declare(strict_types=1);

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\BankClearanceLinkageResolver;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('resolver maps fund posting clearance payload with posting member id', function () {
    $member = Member::create([
        'member_number' => 'CLR-POST-001',
        'name' => 'Clear Posting Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $posting = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 500,
        'status' => 'accepted',
    ]);

    $statement = BankStatement::create([
        'filename' => 'clear-linkage-posting.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $uncleared = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Uncleared posting',
        'amount' => 500,
        'status' => 'imported',
        'member_id' => null,
        'fund_posting_id' => $posting->id,
        'hash' => md5('clear-linkage-posting'),
        'is_cleared' => false,
    ]);

    $payload = app(BankClearanceLinkageResolver::class)->forFundPosting($uncleared);

    expect($payload)->toMatchArray([
        'fund_posting_id' => $posting->id,
        'membership_application_id' => null,
        'status' => 'posted',
        'member_id' => $member->id,
    ]);
});

test('resolver maps cash-out clearance payload with uncleared member id', function () {
    $member = Member::create([
        'member_number' => 'CLR-CASH-001',
        'name' => 'Clear Cash-Out Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $cashOutRequest = CashOutRequest::create([
        'member_id' => $member->id,
        'amount' => 250,
        'status' => 'pending',
    ]);

    $statement = BankStatement::create([
        'filename' => 'clear-linkage-cashout.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $uncleared = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Uncleared cash out',
        'amount' => -250,
        'status' => 'imported',
        'member_id' => $member->id,
        'cash_out_request_id' => $cashOutRequest->id,
        'hash' => md5('clear-linkage-cashout'),
        'is_cleared' => false,
    ]);

    $payload = app(BankClearanceLinkageResolver::class)->forCashOut($uncleared);

    expect($payload)->toMatchArray([
        'cash_out_request_id' => $cashOutRequest->id,
        'status' => 'posted',
        'member_id' => $member->id,
    ]);
});
