<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberGuaranteedLoanInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    $this->guarantor = Member::create([
        'member_number' => 'MEM-GUAR',
        'name' => 'Guarantor Member',
        'email' => 'guarantor@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->borrower = Member::create([
        'member_number' => 'MEM-BOR',
        'name' => 'Borrower Member',
        'email' => 'borrower@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->guarantor);
    app(AccountingService::class)->createMemberAccounts($this->borrower);
});

test('guaranteed loan insights returns empty without member', function () {
    expect(app(MemberGuaranteedLoanInsightsService::class)->snapshot(null))->toBe([]);
});

test('guaranteed loan insights summarizes guarantor portfolio', function () {
    $activeLoan = Loan::factory()->for($this->borrower)->create([
        'guarantor_member_id' => $this->guarantor->id,
        'status' => 'active',
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
    ]);

    LoanInstallment::create([
        'loan_id' => $activeLoan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);

    Loan::factory()->for($this->borrower)->create([
        'guarantor_member_id' => $this->guarantor->id,
        'status' => 'pending',
        'amount_requested' => 2_000,
    ]);

    $snapshot = app(MemberGuaranteedLoanInsightsService::class)->snapshot($this->guarantor);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'sparkline', 'exposure', 'recent', 'index_url'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and(collect($snapshot['kpis'])->firstWhere('label', __('Active'))['value'])->toBe('1')
        ->and(collect($snapshot['kpis'])->firstWhere('label', __('Pending'))['value'])->toBe('1')
        ->and($snapshot['recent'])->toHaveCount(2)
        ->and((int) $snapshot['exposure']['overdue_emis'])->toBe(1);
});
