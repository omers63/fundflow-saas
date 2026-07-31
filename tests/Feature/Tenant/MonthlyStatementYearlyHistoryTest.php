<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm(null);

    $this->memberUser = User::create([
        'name' => 'Yearly History Member',
        'email' => 'yearly-history@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    // Mid-month join: contribution periods are stored as YYYY-MM-01.
    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-YH-88',
        'name' => 'Yearly History Member',
        'email' => 'yearly-history@fund.test',
        'phone' => '0500000088',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-07-30'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

test('yearly history includes join-month contribution when member joined mid-month', function () {
    $fund = $this->member->fundAccount;
    $running = 0.0;

    // Jul–Dec: 1000 + 5×500 = 3500 (mirrors member 88: join-month would be dropped by join-day clamp).
    $amounts = [7 => 1000.0, 8 => 500.0, 9 => 500.0, 10 => 500.0, 11 => 500.0, 12 => 500.0];

    foreach ($amounts as $month => $amount) {
        Contribution::query()->create([
            'member_id' => $this->member->id,
            'period' => Contribution::periodDate($month, 2024),
            'amount' => $amount,
            'status' => 'posted',
            'paid_at' => Carbon::create(2024, $month, 15)->toDateString(),
        ]);

        $running += $amount;
        Transaction::query()->create([
            'account_id' => $fund->id,
            'member_id' => $this->member->id,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $running,
            'description' => "Contribution {$month}/2024",
            'transacted_at' => Carbon::create(2024, $month, 15)->toDateTimeString(),
        ]);
    }

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2025-10');
    $row2024 = collect($statement->details['yearly_history'] ?? [])->firstWhere('year', 2024);

    // Join-day clamp (2024-07-30) would omit period 2024-07-01 → 2500 contributions vs 3500 fund.
    expect($row2024)->not->toBeNull();
    expect($row2024['contributions'])->toEqual(3500);
    expect($row2024['fund_balance'])->toEqual(3500);
});
