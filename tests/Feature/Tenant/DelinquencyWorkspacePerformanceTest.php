<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LoanInsightsService;
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

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_DELINQUENCY);

    $this->actingAs(User::create([
        'name' => 'Delinquency Perf Admin',
        'email' => 'delinquency-perf-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('switching between table tabs keeps working without double rebuild errors', function () {
    Livewire::test(DelinquencyWorkspacePage::class)
        ->call('setSideTab', 'overdue')
        ->assertSet('sideTab', 'overdue')
        ->assertSuccessful()
        ->call('setSideTab', 'guarantor')
        ->assertSet('sideTab', 'guarantor')
        ->assertSuccessful()
        ->call('setSideTab', 'policy')
        ->assertSet('sideTab', 'policy')
        ->assertSuccessful()
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->assertSuccessful();
});

test('digest counts are reused until delinquency insights generation bump', function () {
    $accounting = app(AccountingService::class);

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 5_000,
        'disbursed_at' => Carbon::parse('2025-01-01'),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 250,
        'due_date' => Carbon::parse('2026-01-10'),
        'status' => 'overdue',
    ]);

    $first = app(LoanDelinquencyService::class)->digestCounts();
    expect($first['overdue_installments'])->toBeGreaterThanOrEqual(1)
        ->and($first['overdue_amount'])->toBeGreaterThanOrEqual(250.0);

    $again = app(LoanDelinquencyService::class)->digestCounts();
    expect($again)->toBe($first);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_DELINQUENCY);

    $afterBump = app(LoanDelinquencyService::class)->digestCounts();
    expect($afterBump['overdue_installments'])->toBeGreaterThanOrEqual(1)
        ->and($afterBump)->toHaveKey('overdue_amount');
});

test('delinquency snapshot reuses digest counts cache', function () {
    $first = app(LoanInsightsService::class)->delinquencySnapshot();
    $second = app(LoanInsightsService::class)->delinquencySnapshot();

    expect($second)->toBe($first)
        ->and($first)->toHaveKeys(['hero', 'kpis', 'pipeline']);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_DELINQUENCY);

    $afterBump = app(LoanInsightsService::class)->delinquencySnapshot();
    expect($afterBump)->toHaveKeys(['hero', 'kpis', 'pipeline']);
});
