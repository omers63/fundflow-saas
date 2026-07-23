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

test('running contribution cycle refreshes pending counts and insights snapshot', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    $member->cashAccount()->update(['balance' => 5000]);

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
    $cycleKey = $cycles->contributionCycleKey($this->month, $this->year);

    Livewire::test(ListContributions::class)
        ->assertTableActionExists('runContributionCycle')
        ->callTableAction('runContributionCycle', data: [
            'cycle' => $cycleKey,
            'month' => $this->month,
            'year' => $this->year,
            'collect_oldest_arrears_first' => false,
        ])
        ->assertNotified();

    expect(Contribution::query()->where('member_id', $member->id)->forPeriod($this->month, $this->year)->posted()->exists())->toBeTrue();

    $pendingAfter = ContributionResource::pendingCountForPeriod($this->month, $this->year);
    $afterMissing = app(ContributionInsightsService::class)->forContext('collect')['open_period']['missing_members'];

    expect($pendingAfter)->toBeLessThan($pendingBefore)
        ->and($afterMissing)->toBe($pendingAfter);
});
