<?php

use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Services\FundPostingSettlementSummary;
use App\Support\Notifications\FundPostingNotificationFormatter;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('notification details html uses bidi attributes for arabic names', function () {
    $html = FundPostingNotificationFormatter::renderDetails([
        [
            'label' => 'Member',
            'value' => 'محمد أحمد',
            'bidi' => true,
        ],
        [
            'label' => 'Amount',
            'value' => '1,000.00 USD',
            'emphasis' => true,
        ],
    ]);

    expect($html)
        ->toContain('ff-notification-details')
        ->toContain('dir="auto"')
        ->toContain('محمد أحمد')
        ->toContain('ff-notification-emphasis');
});

test('member accepted body groups deposit and settlement sections', function () {
    $member = Member::create([
        'member_number' => 'MEM-NF',
        'name' => 'محمد أحمد',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $posting = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 500,
        'reference' => 'REF-1',
        'status' => 'accepted',
    ]);

    $html = FundPostingNotificationFormatter::memberAcceptedBody(
        $posting,
        new FundPostingSettlementSummary(
            depositAmount: 500,
            contributionsApplied: 500,
            loanInstallmentsApplied: 0,
            remainingCash: 0,
        ),
    );

    expect($html)
        ->toContain('ff-notification-section')
        ->toContain('REF-1')
        ->toContain('dir="auto"');
});

test('admin new request body includes member name with bidi', function () {
    $member = Member::create([
        'member_number' => 'MEM-ADM',
        'name' => 'محمد أحمد',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $posting = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 500,
        'status' => 'pending',
    ]);

    $html = FundPostingNotificationFormatter::adminNewRequestBody($posting);

    expect($html)
        ->toContain('محمد أحمد')
        ->toContain('dir="auto"');
});
