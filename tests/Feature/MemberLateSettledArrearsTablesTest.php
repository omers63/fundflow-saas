<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\RepaymentsRelationManager;
use App\Models\Tenant\Contribution;
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

    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    Contribution::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Late Table Admin',
        'email' => 'late-table-admin@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'MEM-LATE-UI',
        'name' => 'Late UI Member',
        'email' => 'late-ui@test.com',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->actingAs($this->admin, 'tenant');
    Filament::setCurrentPanel('tenant');
});

test('member contributions table shows late settled posted rows in red styling label', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(1, 2025),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'posted',
        'is_late' => true,
        'posted_at' => Carbon::parse('2025-02-01'),
    ]);

    Livewire::test(ContributionsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(
            Contribution::query()->where('member_id', $this->member->id)->get()
        )
        ->assertSee(__('Posted (late)'));
});

test('member repayments table shows paid late installments with late label', function () {
    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 1000,
        'status' => 'active',
        'applied_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonths(2)->startOfMonth(),
        'status' => 'paid',
        'paid_at' => now()->subMonth(),
        'is_late' => true,
        'late_fee_amount' => 50,
    ]);

    Livewire::test(RepaymentsRelationManager::class, [
        'ownerRecord' => $this->member,
        'pageClass' => EditMember::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('Paid (late)'));
});
