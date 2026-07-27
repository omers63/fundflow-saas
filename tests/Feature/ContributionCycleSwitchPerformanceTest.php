<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\CollectionInsightsCache;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app()->setLocale('en');

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_CONTRIBUTIONS);

    $this->actingAs(User::create([
        'name' => 'Cycle Perf Admin',
        'email' => 'cycle-perf-admin-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('switching between past cycles keeps arrears layout and shows period heading', function () {
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $firstPast = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $secondPast = $firstPast->copy()->subMonthNoOverflow();
    $firstKey = $cycles->contributionCycleKey((int) $firstPast->month, (int) $firstPast->year);
    $secondKey = $cycles->contributionCycleKey((int) $secondPast->month, (int) $secondPast->year);
    $firstLabel = $cycles->periodLabel((int) $firstPast->month, (int) $firstPast->year);
    $secondLabel = $cycles->periodLabel((int) $secondPast->month, (int) $secondPast->year);

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    Livewire::test(ListContributions::class)
        ->set('selectedCycle', $firstKey)
        ->assertSuccessful()
        ->assertSet('cycleSegment', 'arrears')
        ->assertSee($firstLabel, false)
        ->set('selectedCycle', $secondKey)
        ->assertSuccessful()
        ->assertSet('cycleSegment', 'arrears')
        ->assertSee($secondLabel, false);
});

test('pending member ids are reused until collection insights generation bump', function () {
    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $first = $cycles->pendingMemberIdsForPeriod($month, $year);
    expect($first)->toContain($member->id);

    // New service instance still hits the shared CollectionInsightsCache entry.
    $again = app(ContributionCycleService::class)->pendingMemberIdsForPeriod($month, $year);
    expect($again)->toBe($first);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_CONTRIBUTIONS);

    // After bump, recompute still returns a coherent list for the period.
    $afterBump = app(ContributionCycleService::class)->pendingMemberIdsForPeriod($month, $year);
    expect($afterBump)->toContain($member->id);
});

test('arrears stats for cycle share one cached matrix for periods and amount', function () {
    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($year, $month, 1)->subMonthNoOverflow();
    $prevMonth = (int) $previous->month;
    $prevYear = (int) $previous->year;

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 750,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate($prevMonth, $prevYear),
        'amount' => 750,
        'amount_due' => 750,
        'status' => 'pending',
        'late_fee_amount' => 0,
    ]);

    $delinquency = app(LoanDelinquencyService::class);
    $first = $delinquency->contributionArrearsStatsForCycle($prevMonth, $prevYear, live: false);
    $second = $delinquency->contributionArrearsStatsForCycle($prevMonth, $prevYear, live: false);

    expect($first['periods'])->toBeGreaterThanOrEqual(1)
        ->and($first['amount'])->toBeGreaterThanOrEqual(750.0)
        ->and($second)->toBe($first)
        ->and($delinquency->countContributionArrearsPeriods(null, $prevMonth, $prevYear, false))->toBe($first['periods'])
        ->and($delinquency->contributionArrearsAmountTotal(null, $prevMonth, $prevYear, false))->toBe($first['amount']);
});
