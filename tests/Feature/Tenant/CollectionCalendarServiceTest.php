<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\CollectionCalendarPage;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\CollectionCalendarService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->service = app(CollectionCalendarService::class);
    Setting::set('contribution', 'cycle_start_day', '6');
});

test('collection calendar places paid emis on paid date not due date', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'status' => 'completed',
    ]);

    $paidAt = Carbon::parse('2025-10-28 14:30:00');

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 6,
        'due_date' => Carbon::parse('2025-06-10'),
        'paid_at' => $paidAt,
        'status' => 'paid',
        'amount' => 400,
    ]);

    $octoberGrid = $this->service->monthGrid(2025, 10);
    $juneGrid = $this->service->monthGrid(2025, 6);

    expect(collect($octoberGrid)->sum('paid_on_emi'))->toBe(1)
        ->and(collect($octoberGrid)->sum('paid_on_emi_amount'))->toBe(400.0)
        ->and(collect($juneGrid)->sum('paid_on_emi'))->toBe(0);

    $octoberItems = $this->service->emisForDate('2025-10-28');

    expect($octoberItems)->toHaveCount(1)
        ->and($this->service->emisForDate('2025-06-10'))->toHaveCount(0);
});

test('collection calendar places posted contributions on posted date not period month', function () {
    $member = Member::factory()->create();

    Contribution::create([
        'member_id' => $member->id,
        'period' => Carbon::parse('2025-06-01'),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => Carbon::parse('2025-10-28 09:00:00'),
        'paid_at' => Carbon::parse('2025-10-28 09:00:00'),
    ]);

    $octoberGrid = $this->service->monthGrid(2025, 10);
    $juneGrid = $this->service->monthGrid(2025, 6);

    expect(collect($octoberGrid)->sum('paid_on_contribution'))->toBe(1)
        ->and(collect($octoberGrid)->sum('paid_on_contribution_amount'))->toBe(500.0)
        ->and(collect($juneGrid)->sum('paid_on_contribution'))->toBe(0);

    expect($this->service->contributionsForDate('2025-10-28'))->toHaveCount(1)
        ->and($this->service->contributionsForDate('2025-06-01'))->toHaveCount(0);
});

test('collection calendar keeps open emis on due date', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'status' => 'active',
    ]);

    $dueDate = now()->startOfMonth()->addDays(4);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => $dueDate,
        'status' => 'pending',
        'amount' => 250,
    ]);

    $grid = $this->service->monthGrid((int) now()->year, (int) now()->month);

    expect(collect($grid)->sum('to_collect_emi'))->toBe(1)
        ->and(collect($grid)->sum('to_collect_emi_amount'))->toBe(250.0);
});

test('collection calendar opens on the configured business day month', function () {
    Setting::set('general', 'business_day', '2026-08-15');
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Calendar Business Day Admin',
        'email' => 'calendar-business-day@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(CollectionCalendarPage::class)
        ->assertSet('calendarYear', 2026)
        ->assertSet('calendarMonth', 8);
});

test('collection calendar groups same-day collections using the business day date', function () {
    Setting::set('general', 'business_day', '2026-08-15');

    $member = Member::factory()->create();

    Contribution::create([
        'member_id' => $member->id,
        'period' => Carbon::parse('2026-07-01'),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => BusinessDay::now(),
        'paid_at' => BusinessDay::now(),
    ]);

    $grid = $this->service->monthGrid(2026, 8);

    expect($grid[15]['paid_on_contribution'] ?? 0)->toBe(1)
        ->and($grid[15]['paid_on_contribution_amount'] ?? 0.0)->toBe(500.0);
});
