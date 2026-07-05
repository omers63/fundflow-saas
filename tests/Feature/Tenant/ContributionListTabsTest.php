<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Central\Tenant;
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
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $admin = User::create([
        'name' => 'Contributions Admin',
        'email' => 'contributions-tabs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('contributions list defaults to contributions tab', function () {
    Livewire::test(ListContributions::class)
        ->assertSuccessful()
        ->assertSee(__('Full contribution history, filters, and manual posting.'), false);

    expect(ContributionResource::listUrl())
        ->toBe(ContributionResource::listUrl('contributions'))
        ->not->toContain('tab=');

    expect(ContributionResource::listUrl('collect'))
        ->toContain('tab=collect');
});

test('contributions list cycle selector drives collect tab data', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousKey = $cycles->contributionCycleKey((int) $previous->month, (int) $previous->year);

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
        ->set('activeTab', 'collect')
        ->set('selectedCycle', $previousKey)
        ->assertSee($cycles->periodLabel((int) $previous->month, (int) $previous->year), false)
        ->assertSee($member->name, false);

    Carbon::setTestNow();
});

test('contributions list has unified open cycle collect and collected tabs', function () {
    Livewire::test(ListContributions::class)
        ->assertSuccessful()
        ->assertSee(__('Contributions'), false)
        ->assertSee(__('To collect'), false)
        ->assertSee(__('Collected'), false);

    $url = ContributionResource::listTabUrl('collect');
    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/contributions';
    $query = parse_url($url, PHP_URL_QUERY);

    expect($query)->toBe('tab=collect');

    $this->get('http://'.$this->domain.$path.'?'.$query)
        ->assertSuccessful()
        ->assertSee(__('To collect'), false);
});

test('contribution table header actions appear on the correct tabs', function () {
    Livewire::test(ListContributions::class)
        ->assertTableActionExists('importContributions')
        ->assertTableActionExists('exportContributions')
        ->assertTableActionExists('create')
        ->assertTableActionExists('generateMonthly')
        ->assertTableActionDoesNotExist('runDelinquencyMaintenance');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'collect')
        ->assertTableActionExists('generateMonthly')
        ->assertTableActionExists('runDelinquencyMaintenance')
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('importContributions')
        ->assertTableActionDoesNotExist('exportContributions');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'arrears')
        ->assertTableActionExists('runDelinquencyMaintenance')
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('generateMonthly')
        ->assertTableActionDoesNotExist('importContributions');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'collected')
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('generateMonthly')
        ->assertTableActionDoesNotExist('runDelinquencyMaintenance')
        ->assertTableActionDoesNotExist('importContributions');
});

test('contribution tab insights use context-specific snapshots', function () {
    $service = app(ContributionInsightsService::class);

    expect($service->forContext('collect'))
        ->toHaveKey('hero')
        ->and($service->forContext('collect')['pipeline'])
        ->toHaveKeys(['collect_url', 'arrears_url']);

    expect($service->forContext('arrears')['hero']['tone'])
        ->toBeIn(['danger', 'success'])
        ->and($service->forContext('arrears')['pipeline']['arrears_periods'])
        ->toBeInt();
});

test('collect tab table search does not query virtual columns', function () {
    Livewire::test(ListContributions::class)
        ->set('activeTab', 'collect')
        ->set('tableSearch', 'ع')
        ->assertSuccessful();
});

test('contributions tab renders waived status without error', function () {
    $member = Member::factory()->create(['status' => 'active']);

    Contribution::factory()->for($member)->create([
        'status' => 'waived',
        'period' => now()->startOfMonth()->toDateString(),
    ]);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'contributions')
        ->assertSuccessful()
        ->assertSee(__('Waived'), false);
});

test('legacy contribution cycle route redirects to collect tab', function () {
    $legacyPath = parse_url(ContributionCyclePage::getUrl(), PHP_URL_PATH) ?? '/admin/contribution-cycle';
    $collectUrl = ContributionResource::listTabUrl('collect');
    $collectPath = parse_url($collectUrl, PHP_URL_PATH) ?? '/admin/contributions';
    $collectQuery = parse_url($collectUrl, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$legacyPath)
        ->assertRedirect($collectPath.($collectQuery ? '?'.$collectQuery : ''));
});
