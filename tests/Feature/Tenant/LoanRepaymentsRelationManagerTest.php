<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\RepaymentsRelationManager;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Loans\LoanRepaymentLogService;
use App\Support\LoanRepaymentNote;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
});

test('actual repayments tab is hidden when loan has no posted installments', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create();

    expect(RepaymentsRelationManager::canViewForRecord($loan, ViewLoan::class))->toBeFalse();
});

test('actual repayments tab is visible when loan has paid installments', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create();

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'pending',
    ]);

    $installment->update([
        'status' => 'paid',
        'paid_at' => now(),
        'amount_collected' => 1000,
    ]);

    expect(RepaymentsRelationManager::canViewForRecord($loan->fresh(), ViewLoan::class))->toBeTrue();
});

test('actual repayments tab remains visible for legacy import rows', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create();

    $loan->repayments()->create([
        'amount' => 1000,
        'paid_at' => now(),
        'notes' => 'Imported',
    ]);

    expect(RepaymentsRelationManager::canViewForRecord($loan->fresh(), ViewLoan::class))->toBeTrue();
});

test('actual repayments relation manager renders early settle header without error', function () {
    Filament::setCurrentPanel('tenant');

    $member = Member::factory()->create(['status' => 'active']);
    $loan = Loan::factory()->for($member)->create(['status' => 'active']);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'pending',
    ])->update([
        'status' => 'paid',
        'paid_at' => now(),
        'amount_collected' => 1000,
    ]);

    Livewire::actingAs(User::create([
        'name' => 'Admin',
        'email' => 'repayments-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant')
        ->test(RepaymentsRelationManager::class, [
            'ownerRecord' => $loan->fresh(),
            'pageClass' => ViewLoan::class,
        ])
        ->assertSuccessful()
        ->assertSee(__('EMI repayment'));
});

test('early settled loan shows one settlement repayment line not per emi', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $settledAt = now()->subDays(3);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'early_settled',
        'settled_at' => $settledAt,
    ]);

    foreach (range(1, 3) as $number) {
        LoanInstallment::query()->create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => $settledAt->copy()->subMonths(4 - $number),
            'status' => 'paid',
            'paid_at' => $settledAt,
            'amount_collected' => 1000,
        ]);
    }

    expect($loan->repayments()->count())->toBe(0);

    expect(RepaymentsRelationManager::canViewForRecord($loan->fresh(), ViewLoan::class))->toBeTrue();

    $loan->refresh();

    expect($loan->repayments()->count())->toBe(1)
        ->and($loan->repayments()->first()->notes)->toBe(LoanRepaymentNote::fullEarlySettlement())
        ->and((float) $loan->repayments()->first()->amount)->toBe(3000.0);
});

test('backfill is skipped when settlement repayment already exists', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $settledAt = now()->subDays(3);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'early_settled',
        'settled_at' => $settledAt,
    ]);

    $loan->repayments()->create([
        'amount' => 1500,
        'paid_at' => $settledAt,
        'notes' => LoanRepaymentNote::fullEarlySettlement(),
    ]);

    app(LoanRepaymentLogService::class)->backfillSettlementRepaymentIfMissing($loan->fresh());

    expect($loan->fresh()->repayments()->count())->toBe(1);
});
