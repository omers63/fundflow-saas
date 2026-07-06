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
use App\Services\Loans\LoanDelinquencyService;
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

test('contributions list defaults to cycle tab with collect segment', function () {
    Livewire::test(ListContributions::class)
        ->assertSuccessful()
        ->assertSet('activeTab', 'cycle')
        ->assertSet('cycleSegment', 'collect')
        ->assertSee(__('To collect'), false);

    expect(ContributionResource::listUrl())
        ->toBe(ContributionResource::listUrl('cycle'))
        ->not->toContain('tab=');

    expect(ContributionResource::listTabUrl('collect'))
        ->not->toContain('tab=collect');
});

test('contributions list cycle selector drives collect segment data', function () {
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
        ->set('selectedCycle', $previousKey)
        ->assertSee($cycles->periodLabel((int) $previous->month, (int) $previous->year), false)
        ->assertSee($member->name, false);

    Carbon::setTestNow();
});

test('contributions list has cycle and ledger primary tabs', function () {
    Livewire::test(ListContributions::class)
        ->assertSuccessful()
        ->assertSee(__('Cycle'), false)
        ->assertSee(__('Ledger'), false)
        ->assertSee(__('To collect'), false)
        ->assertSee(__('Collected'), false);

    $url = ContributionResource::listTabUrl('collected');
    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/contributions';
    $query = parse_url($url, PHP_URL_QUERY);

    expect($query)->toContain('segment=collected');

    $this->get('http://'.$this->domain.$path.'?'.$query)
        ->assertSuccessful()
        ->assertSee(__('Collected'), false);
});

test('legacy contribution tab urls redirect to cycle and ledger routes', function () {
    $path = parse_url(ContributionResource::getUrl('index'), PHP_URL_PATH) ?? '/admin/contributions';

    $this->get('http://'.$this->domain.$path.'?tab=collect')
        ->assertRedirect($path);

    $arrearsUrl = ContributionResource::listTabUrl('arrears');
    $arrearsQuery = parse_url($arrearsUrl, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.'?tab=arrears')
        ->assertRedirect($path.'?'.$arrearsQuery);
});

test('contribution actions appear on the correct tabs', function () {
    Livewire::test(ListContributions::class)
        ->assertActionExists('generateMonthly')
        ->assertTableActionDoesNotExist('importContributions')
        ->assertTableActionDoesNotExist('create');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertTableActionExists('importContributions')
        ->assertTableActionExists('exportContributions')
        ->assertTableActionExists('create');

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->assertTableActionExists('runDelinquencyMaintenance')
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('generateMonthly')
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

test('collect segment table search does not query virtual columns', function () {
    Livewire::test(ListContributions::class)
        ->set('tableSearch', 'ع')
        ->assertSuccessful();
});

test('ledger tab renders waived status without error', function () {
    $member = Member::factory()->create(['status' => 'active']);

    Contribution::factory()->for($member)->create([
        'status' => 'waived',
        'period' => now()->startOfMonth()->toDateString(),
    ]);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertSuccessful()
        ->assertSee(__('Waived'), false);
});

test('legacy contribution cycle route redirects to collect segment', function () {
    $legacyPath = parse_url(ContributionCyclePage::getUrl(), PHP_URL_PATH) ?? '/admin/contribution-cycle';
    $collectUrl = ContributionResource::listTabUrl('collect');
    $collectPath = parse_url($collectUrl, PHP_URL_PATH) ?? '/admin/contributions';
    $collectQuery = parse_url($collectUrl, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$legacyPath)
        ->assertRedirect($collectPath.($collectQuery ? '?'.$collectQuery : ''));
});

test('arrears ledger view url includes tab and view parameters', function () {
    $url = ContributionResource::listTabUrl('arrears');

    expect($url)->toContain('tab=ledger')
        ->and($url)->toContain('view=arrears');
});

test('stale arrears view query on cycle tab does not break the table', function () {
    Livewire::test(ListContributions::class)
        ->set('activeTab', 'cycle')
        ->set('ledgerView', 'arrears')
        ->assertSuccessful()
        ->assertSet('activeTab', 'cycle')
        ->assertSee(__('To collect'), false);
});

test('ledger arrears view respects the selected collection cycle', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $recent = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $recentMonth = (int) $recent->month;
    $recentYear = (int) $recent->year;
    $julyKey = $cycles->contributionCycleKey(7, 2025);
    $julyLabel = $cycles->periodLabel(7, 2025);

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate($recentMonth, $recentYear),
        'amount' => 500,
        'status' => 'pending',
    ]);

    Contribution::factory()->for($member)->create([
        'period' => Contribution::periodDate(7, 2025),
        'amount' => 500,
        'status' => 'pending',
    ]);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->set('selectedCycle', $julyKey)
        ->assertSuccessful()
        ->assertSee($member->name, false)
        ->assertSee($julyLabel, false);

    $scopedRows = app(LoanDelinquencyService::class)
        ->contributionArrearsTableRecords(null, 7, 2025, false)
        ->where('member_id', $member->id);

    expect($scopedRows->contains(
        fn (array $row): bool => $row['month'] === 7 && $row['year'] === 2025,
    ))->toBeTrue()
        ->and($scopedRows->contains(
            fn (array $row): bool => $row['month'] === $recentMonth && $row['year'] === $recentYear,
        ))->toBeFalse();

    Carbon::setTestNow();
});
