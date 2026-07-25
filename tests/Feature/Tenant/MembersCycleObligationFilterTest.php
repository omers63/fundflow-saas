<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    $this->actingAs(User::create([
        'name' => 'Members Filter Admin',
        'email' => 'members-cycle-filter@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('members list filters contribution cycle versus loan repayment obligations', function (): void {
    $contributor = Member::factory()->create([
        'name' => 'Cycle Contributor',
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'contribution_cycles_active' => true,
    ]);

    $borrower = Member::factory()->create([
        'name' => 'Repayment Borrower',
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'contribution_cycles_active' => true,
    ]);

    $loan = Loan::factory()->for($borrower)->create([
        'status' => 'active',
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$contributor, $borrower])
        ->filterTable('cycle_obligation', 'contribution')
        ->assertCanSeeTableRecords([$contributor])
        ->assertCanNotSeeTableRecords([$borrower])
        ->filterTable('cycle_obligation', 'loan_repayment')
        ->assertCanSeeTableRecords([$borrower])
        ->assertCanNotSeeTableRecords([$contributor]);
});

test('member scopes classify contribution cycle and loan repayment cohorts', function (): void {
    $contributor = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
    ]);

    $borrower = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
    ]);

    $loan = Loan::factory()->for($borrower)->create(['status' => 'active']);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    expect(Member::query()->underContributionCycle()->pluck('id')->all())
        ->toContain($contributor->id)
        ->not->toContain($borrower->id)
        ->and(Member::query()->withActiveLoanRepaymentObligation()->pluck('id')->all())
        ->toContain($borrower->id)
        ->not->toContain($contributor->id);
});
