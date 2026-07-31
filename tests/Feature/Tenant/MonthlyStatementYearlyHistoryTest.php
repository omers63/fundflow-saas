<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm(null);
    app()->setLocale('en');

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

test('yearly history defers cross-year paid contributions to the payment year', function () {
    $user = User::create([
        'name' => 'Cross Year Member',
        'email' => 'yearly-cross@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-YH-87',
        'name' => 'Cross Year Member',
        'email' => 'yearly-cross@fund.test',
        'phone' => '0500000087',
        'monthly_contribution_amount' => 3000,
        'joined_at' => Carbon::parse('2024-03-11'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $fund = $member->fundAccount;
    $running = 0.0;

    // Mar–Nov paid in 2024 → 27K on fund by year-end; Dec period paid in Jan 2025.
    foreach ([3, 5, 6, 7, 8, 9, 10, 11, 12] as $month) {
        $amount = $month === 5 ? 6000.0 : 3000.0;
        $paidAt = $month === 12
            ? Carbon::parse('2025-01-02')
            : Carbon::create(2024, $month, min(28, 10 + $month));

        Contribution::query()->create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2024),
            'amount' => $amount,
            'status' => 'posted',
            'paid_at' => $paidAt->toDateString(),
        ]);

        if ($month === 12) {
            continue;
        }

        $running += $amount;
        Transaction::query()->create([
            'account_id' => $fund->id,
            'member_id' => $member->id,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $running,
            'description' => "Contribution {$month}/2024",
            'transacted_at' => $paidAt->toDateTimeString(),
        ]);
    }

    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 3000,
        'balance_after' => 30000,
        'description' => 'Contribution 12/2024',
        'transacted_at' => '2025-01-02 00:00:00',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($member, '2025-10');
    $history = collect($statement->details['yearly_history'] ?? []);
    $row2024 = $history->firstWhere('year', 2024);
    $row2025 = $history->firstWhere('year', 2025);

    // Period paid by Dec-cycle end stays in that year (Dec cycle ends early January).
    expect($row2024)->not->toBeNull();
    expect($row2024['contributions'])->toEqual(30000);
    expect($row2024['fund_balance'])->toEqual(30000);
    expect($row2024['through'] ?? null)->toBeNull();
    expect($row2024['cycle_start'])->toBe(
        app(ContributionCycleService::class)->cycleStartAt(1, 2024)->toDateString()
    );
    expect($row2024['cycle_end'])->toBe(
        app(ContributionCycleService::class)->cycleDueEndAt(12, 2024)->toDateString()
    );
    expect($row2025)->not->toBeNull();
    expect($row2025['contributions'])->toEqual(0);
    expect($row2025['fund_balance'])->toEqual(30000);
        expect($row2025['through'])->toBe(
            app(ContributionCycleService::class)->cycleDueEndAt(10, 2025)->toDateString()
        );
});

test('october statement year row matches fund credits through october not full calendar year', function () {
    $user = User::create([
        'name' => 'Partial Year Member',
        'email' => 'yearly-partial@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-YH-PARTIAL',
        'name' => 'Partial Year Member',
        'email' => 'yearly-partial@fund.test',
        'phone' => '0500000086',
        'monthly_contribution_amount' => 3000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $fund = $member->fundAccount;
    $running = 0.0;

    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $month) {
        $amount = 3000.0;
        $paidAt = Carbon::create(2025, $month, 15);

        Contribution::query()->create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2025),
            'amount' => $amount,
            'status' => 'posted',
            'paid_at' => $paidAt->toDateString(),
        ]);

        $running += $amount;
        Transaction::query()->create([
            'account_id' => $fund->id,
            'member_id' => $member->id,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $running,
            'description' => "Contribution {$month}/2025",
            'transacted_at' => $paidAt->toDateTimeString(),
        ]);
    }

    $statement = app(MonthlyStatementService::class)->generateForMember($member, '2025-10');
    $row2025 = collect($statement->details['yearly_history'] ?? [])->firstWhere('year', 2025);

    // Full-year fund credits = 33K; October statement must stop at Oct cycle end → 30K.
    expect($row2025)->not->toBeNull();
    expect($row2025['through'])->toBe(
        app(ContributionCycleService::class)->cycleDueEndAt(10, 2025)->toDateString()
    );
    expect($row2025['contributions'])->toEqual(30000);
    expect($row2025['fund_balance'])->toEqual(30000);
});

test('six-cycle activity buckets contributions by payment date within cycle windows', function () {
    $user = User::create([
        'name' => 'Activity Ledger Member',
        'email' => 'yearly-activity@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-YH-ACT',
        'name' => 'Activity Ledger Member',
        'email' => 'yearly-activity@fund.test',
        'phone' => '0500000085',
        'monthly_contribution_amount' => 3000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $cycles = app(ContributionCycleService::class);

    // June period paid early July — still inside the June cycle window (starts day 6).
    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(6, 2025),
        'amount' => 3000,
        'status' => 'posted',
        'paid_at' => '2025-07-02',
    ]);
    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(8, 2025),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2025-09-01',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($member, '2025-10');
    $months = collect($statement->details['current_year_months'] ?? []);

    $june = $months->firstWhere('period', '2025-06');
    $july = $months->firstWhere('period', '2025-07');
    $august = $months->firstWhere('period', '2025-08');

    expect($june['contributions'])->toEqual(3000);
    expect($june['contribution_dates'])->toBe(['2025-07-02']);
    expect($june['cycle_start'])->toBe($cycles->cycleStartAt(6, 2025)->toDateString());
    expect($june['cycle_end'])->toBe($cycles->cycleDueEndAt(6, 2025)->toDateString());
    expect($july['contributions'])->toEqual(0);
    expect($august['contributions'])->toEqual(1000);
    expect($august['contribution_dates'])->toBe(['2025-09-01']);
    expect($august['label'])->not->toBeEmpty()
        ->and($august['cycle_start'])->toBe($cycles->cycleStartAt(8, 2025)->toDateString())
        ->and($august['cycle_end'])->toBe($cycles->cycleDueEndAt(8, 2025)->toDateString());
});
test('lifetime summary is capped to the statement closing cycle end', function () {
    BusinessDaySettings::saveFromForm('2025-11-06');

    $user = User::create([
        'name' => 'Lifetime Cap Member',
        'email' => 'yearly-life@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-YH-LIFE',
        'name' => 'Lifetime Cap Member',
        'email' => 'yearly-life@fund.test',
        'phone' => '0500000084',
        'monthly_contribution_amount' => 3000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $fund = $member->fundAccount;
    $cycles = app(ContributionCycleService::class);
    $octCycleEnd = $cycles->cycleDueEndAt(10, 2025)->toDateString();

    $posted = Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(9, 2025),
        'amount' => 50000,
        'status' => 'posted',
        'paid_at' => '2025-10-01',
    ]);

    $pending = Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(11, 2025),
        'amount' => 20000,
        'amount_due' => 20000,
        'amount_collected' => 5000,
        'status' => 'pending',
        'collection_status' => 'partially_pending',
    ]);

    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 50000,
        'balance_after' => 50000,
        'description' => 'Posted contribution',
        'reference_type' => Contribution::class,
        'reference_id' => $posted->id,
        'transacted_at' => '2025-10-01 00:00:00',
    ]);
    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 5000,
        'balance_after' => 55000,
        'description' => 'Partial contribution after period',
        'reference_type' => Contribution::class,
        'reference_id' => $pending->id,
        'transacted_at' => '2025-11-06 09:00:00',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($member, '2025-10');
    $lifetime = $statement->details['lifetime'];

    expect($lifetime['as_of'])->toBe($octCycleEnd);
    expect($statement->details['fund_closing'])->toEqual(50000);
    expect($lifetime['total_contributions'])->toEqual(50000);
    expect($lifetime['fund_balance'])->toEqual(50000);
});
