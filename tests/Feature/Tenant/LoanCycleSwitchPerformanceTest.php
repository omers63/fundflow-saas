<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
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

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_LOAN_EMI);

    $this->actingAs(User::create([
        'name' => 'Loan Cycle Perf Admin',
        'email' => 'loan-cycle-perf-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('switching between past loan cycles keeps arrears layout and shows period heading', function () {
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $firstPast = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $secondPast = $firstPast->copy()->subMonthNoOverflow();
    $firstKey = $cycles->contributionCycleKey((int) $firstPast->month, (int) $firstPast->year);
    $secondKey = $cycles->contributionCycleKey((int) $secondPast->month, (int) $secondPast->year);
    $firstLabel = $cycles->periodLabel((int) $firstPast->month, (int) $firstPast->year);
    $secondLabel = $cycles->periodLabel((int) $secondPast->month, (int) $secondPast->year);

    Livewire::test(ListLoans::class)
        ->set('selectedCycle', $firstKey)
        ->assertSuccessful()
        ->assertSet('collectionSegment', 'arrears')
        ->assertSee($firstLabel, false)
        ->set('selectedCycle', $secondKey)
        ->assertSuccessful()
        ->assertSet('collectionSegment', 'arrears')
        ->assertSee($secondLabel, false);
});

test('emi pending metrics are reused until loan emi insights generation bump', function () {
    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'disbursed_at' => Carbon::parse('2025-01-01'),
    ]);

    [$start] = $cycles->cycleDueDateBounds($month, $year);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => Carbon::parse($start)->addDays(2),
        'status' => 'pending',
    ]);

    $catalog = app(LoanEmiCollectionCatalogService::class);
    $first = $catalog->periodPendingCollectionMetrics($month, $year);
    expect($first['pending_members'])->toBeGreaterThanOrEqual(1);

    $again = app(LoanEmiCollectionCatalogService::class)->periodPendingCollectionMetrics($month, $year);
    expect($again)->toBe($first);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_LOAN_EMI);

    $afterBump = app(LoanEmiCollectionCatalogService::class)->periodPendingCollectionMetrics($month, $year);
    expect($afterBump['pending_members'])->toBeGreaterThanOrEqual(1);
});
