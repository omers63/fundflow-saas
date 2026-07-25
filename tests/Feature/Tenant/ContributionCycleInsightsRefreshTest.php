<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionInsightsService;
use App\Support\ContributionCollectionStatus;
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

    $this->admin = User::create([
        'name' => 'Cycle Insights Admin',
        'email' => 'cycle-insights-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');

    [$this->month, $this->year] = app(ContributionCycleService::class)->currentOpenPeriod();
});

afterEach(function () {
    Carbon::setTestNow();
    ContributionResource::flushPeriodCountCaches();
});

test('contribution insights widget tracks list selected cycle', function () {
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousKey = $cycles->contributionCycleKey((int) $previous->month, (int) $previous->year);
    $previousLabel = $cycles->periodLabel((int) $previous->month, (int) $previous->year);

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate((int) $previous->month, (int) $previous->year),
        'amount' => 500,
        'amount_due' => 500,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    Livewire::test(ListContributions::class)
        ->set('selectedCycle', $previousKey)
        ->assertSet('selectedCycle', $previousKey)
        ->assertSee($previousLabel, false);

    $snapshot = app(ContributionInsightsService::class)->forContext('arrears', $previousKey);

    expect($snapshot['open_period']['label'])->toBe($previousLabel)
        ->and((int) collect($snapshot['kpis'])->firstWhere('key', 'arrears')['value'])->toBeGreaterThanOrEqual(1);
});

test('running contribution cycle refreshes pending counts and insights snapshot', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    $member->load('cashAccount');
    $member->cashAccount?->update(['balance' => 5000]);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate($this->month, $this->year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    expect(Contribution::query()->where('member_id', $member->id)->forPeriod($this->month, $this->year)->posted()->exists())->toBeFalse();

    $pendingBefore = ContributionResource::pendingCountForPeriod($this->month, $this->year);
    expect($pendingBefore)->toBeGreaterThanOrEqual(1);

    $beforeMissing = app(ContributionInsightsService::class)->forContext('collect')['open_period']['missing_members'];
    expect($beforeMissing)->toBe($pendingBefore);

    $cycles = app(ContributionCycleService::class);
    $results = $cycles->applyContributions($this->month, $this->year, false);

    expect($results['applied'])->not->toBeEmpty()
        ->and(Contribution::query()->where('member_id', $member->id)->forPeriod($this->month, $this->year)->posted()->exists())->toBeTrue();

    ContributionResource::flushPeriodCountCaches();

    $pendingAfter = ContributionResource::pendingCountForPeriod($this->month, $this->year);
    $afterMissing = app(ContributionInsightsService::class)->forContext('collect')['open_period']['missing_members'];

    expect($pendingAfter)->toBeLessThan($pendingBefore)
        ->and($afterMissing)->toBe($pendingAfter);
});
