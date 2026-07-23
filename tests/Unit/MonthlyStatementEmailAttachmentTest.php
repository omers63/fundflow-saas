<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Support\StatementSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('statement ready email attaches pdf when setting enabled', function () {
    Setting::set(StatementSettings::GROUP, 'attach_pdf', true);

    $user = User::create([
        'name' => 'Statement Attach',
        'email' => 'statement-attach@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-ATTACH',
        'name' => 'Statement Attach',
        'email' => 'statement-attach@fund.test',
        'phone' => '0500000001',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $statement = MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => '2026-05',
        'opening_balance' => 0,
        'total_contributions' => 0,
        'total_repayments' => 0,
        'closing_balance' => 0,
        'generated_at' => now(),
        'details' => [
            'currency' => 'SAR',
            'member_snapshot' => [
                'name' => $member->name,
                'member_number' => $member->member_number,
            ],
        ],
    ]);

    $mail = (new MonthlyStatementNotification($statement))->toMail($user);

    expect($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toBe('statement-2026-05-MEM-ATTACH.pdf')
        ->and($mail->rawAttachments[0]['options']['mime'] ?? null)->toBe('application/pdf')
        ->and($mail->rawAttachments[0]['data'])->toStartWith('%PDF');
});

test('statement ready email omits pdf when attach setting disabled', function () {
    Setting::set(StatementSettings::GROUP, 'attach_pdf', false);

    $user = User::create([
        'name' => 'Statement No Attach',
        'email' => 'statement-no-attach@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-NO-ATT',
        'name' => 'Statement No Attach',
        'email' => 'statement-no-attach@fund.test',
        'phone' => '0500000002',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $statement = MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => '2026-05',
        'opening_balance' => 0,
        'total_contributions' => 0,
        'total_repayments' => 0,
        'closing_balance' => 0,
        'generated_at' => now(),
        'details' => [
            'currency' => 'SAR',
            'member_snapshot' => [
                'name' => $member->name,
                'member_number' => $member->member_number,
            ],
        ],
    ]);

    $mail = (new MonthlyStatementNotification($statement))->toMail($user);

    expect($mail->rawAttachments)->toBeEmpty();
});
