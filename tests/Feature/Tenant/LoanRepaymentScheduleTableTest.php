<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\InstallmentsRelationManager;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Repayment Schedule Admin',
        'email' => 'repayment-schedule-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $member = Member::factory()->create(['status' => 'active']);
    app(AccountingService::class)->createMemberAccounts($member);

    $this->loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 10000,
        'disbursed_at' => Carbon::parse('2024-04-08'),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $this->loan->id,
        'installment_number' => 17,
        'amount' => 5500,
        'due_date' => Carbon::parse('2025-10-05'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-10-27'),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $this->loan->id,
        'installment_number' => 18,
        'amount' => 5500,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'pending',
    ]);
});

test('repayment schedule table shows contribution cycle for each installment', function () {
    $cycles = app(\App\Services\ContributionCycleService::class);

    Livewire::test(InstallmentsRelationManager::class, [
        'ownerRecord' => $this->loan,
        'pageClass' => EditLoan::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('Cycle'), false)
        ->assertSee($cycles->periodLabel(...$cycles->cyclePeriodForDueDate('2025-10-05')), false)
        ->assertSee($cycles->periodLabel(...$cycles->cyclePeriodForDueDate('2025-11-05')), false);
});
