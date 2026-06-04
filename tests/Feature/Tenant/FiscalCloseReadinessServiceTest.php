<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\FiscalYearClosePage;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\FiscalClose\FiscalCloseGateResult;
use App\Services\FiscalClose\FiscalClosePeriodResolver;
use App\Services\FiscalClose\FiscalCloseReadinessService;
use App\Support\FiscalSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
    Account::query()->delete();
    Member::query()->delete();
    FundPosting::query()->delete();
    CashOutRequest::query()->delete();
    ReconciliationException::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('fiscal settings can be saved and retrieved', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 7,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    expect(FiscalSettings::fiscalYearStartMonth())->toBe(7)
        ->and(FiscalSettings::fiscalYearStartDay())->toBe(1)
        ->and(FiscalSettings::purgePolicy())->toBe(FiscalSettings::PURGE_RETAIN_7Y)
        ->and(FiscalSettings::currentFiscalYearLabel())->toBe('FY2026');
});

test('fiscal period resolver uses calendar year when starting January 1', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_ARCHIVE_THEN_DELETE,
        'current_fiscal_year_label' => 'FY2025',
    ]);

    $period = app(FiscalClosePeriodResolver::class)->resolvePeriodForLabel('FY2025');

    expect($period->periodStart->toDateString())->toBe('2025-01-01')
        ->and($period->periodEnd->toDateString())->toBe('2025-12-31');
});

test('fiscal period resolver uses july fiscal year boundary', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 7,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_ARCHIVE_THEN_DELETE,
        'current_fiscal_year_label' => 'FY2025',
    ]);

    $period = app(FiscalClosePeriodResolver::class)->resolvePeriodForLabel('FY2025');

    expect($period->periodStart->toDateString())->toBe('2024-07-01')
        ->and($period->periodEnd->toDateString())->toBe('2025-06-30');
});

test('readiness report passes when tenant has no blocking items', function () {
    $report = app(FiscalCloseReadinessService::class)->assess();

    expect($report->canProceed())->toBeTrue()
        ->and(collect($report->gates)->every(fn ($gate) => ! $gate->isFail()))->toBeTrue();
});

test('readiness report fails when pending deposit exists', function () {
    $member = Member::factory()->create();

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 100,
        'status' => 'pending',
    ]);

    $report = app(FiscalCloseReadinessService::class)->assess();
    $gate = collect($report->gates)->firstWhere('code', 'OPEN_FUND_POSTINGS');

    expect($report->canProceed())->toBeFalse()
        ->and($gate)->not->toBeNull()
        ->and($gate->status)->toBe(FiscalCloseGateResult::STATUS_FAIL);
});

test('readiness report fails on critical reconciliation exception', function () {
    ReconciliationException::create([
        'exception_code' => 'MASTER_IMBALANCE_UNRESOLVED',
        'domain' => 'master_account',
        'severity' => 'critical',
        'status' => 'open',
        'raised_at' => now(),
    ]);

    $report = app(FiscalCloseReadinessService::class)->assess();
    $gate = collect($report->gates)->firstWhere('code', 'RECON_EXCEPTIONS');

    expect($report->canProceed())->toBeFalse()
        ->and($gate->status)->toBe(FiscalCloseGateResult::STATUS_FAIL);
});

test('fiscal year close page renders for tenant admin', function () {
    Filament::setCurrentPanel('tenant');

    $user = User::create([
        'name' => 'Fund Admin',
        'email' => 'fiscal-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($user, 'tenant');

    Livewire::test(FiscalYearClosePage::class)
        ->assertSuccessful()
        ->assertSee(__('Run readiness checks'))
        ->callAction('run_readiness')
        ->assertSet('readinessReport.can_proceed', true);

    expect(FiscalYearClosePage::getUrl())->toContain('/admin/fiscal-year-close');
});
