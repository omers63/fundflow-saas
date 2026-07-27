<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Tenant\MemberListTabService;
use App\Support\CollectionInsightsCache;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app()->setLocale('en');

    CollectionInsightsCache::bumpAll();

    $this->actingAs(User::create([
        'name' => 'Member Arrears Perf Admin',
        'email' => 'member-arrears-perf-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('outstanding arrears member ids are reused until member insights generation bump', function () {
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 600,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate((int) $previous->month, (int) $previous->year),
        'amount' => 600,
        'amount_due' => 600,
        'status' => 'pending',
    ]);

    $delinquency = app(LoanDelinquencyService::class);
    $first = $delinquency->membersWithOutstandingArrearsIds();
    expect($first)->toContain($member->id);

    $again = app(LoanDelinquencyService::class)->membersWithOutstandingArrearsIds();
    expect($again)->toBe($first);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_MEMBERS);

    $afterBump = app(LoanDelinquencyService::class)->membersWithOutstandingArrearsIds();
    expect($afterBump)->toContain($member->id);
});

test('member tab counts share cached arrears aggregates', function () {
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 400,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate((int) $previous->month, (int) $previous->year),
        'amount' => 400,
        'amount_due' => 400,
        'status' => 'pending',
    ]);

    $tabs = app(MemberListTabService::class);
    $first = $tabs->tabCounts();
    expect($first['delinquent'])->toBeGreaterThanOrEqual(1);

    $again = app(MemberListTabService::class)->tabCounts();
    expect($again)->toBe($first);
});
