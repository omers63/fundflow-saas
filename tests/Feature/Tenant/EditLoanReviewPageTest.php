<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\DisbursementsRelationManager;
use App\Filament\Tenant\Resources\Loans\RelationManagers\InstallmentsRelationManager;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Loan Review Admin',
        'email' => 'loan-review-page@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('pending loan edit page is structured for application review with workflow actions', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_requested' => 15000,
        'purpose' => 'Business expansion',
        'applied_at' => now()->subDay(),
    ]);

    Livewire::test(EditLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful()
        ->assertSee(__('Review loan application #:id', ['id' => $loan->getKey()]), false)
        ->assertSee(__('Application queue'), false)
        ->assertSee(__('Approval preview'), false)
        ->assertSee(__('Application details'), false)
        ->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->assertActionVisible('cancel');
});

test('pre-approval loan hides repayment schedule and disbursement history tabs', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_requested' => 10000,
    ]);

    expect(InstallmentsRelationManager::canViewForRecord($loan, EditLoan::class))->toBeFalse()
        ->and(DisbursementsRelationManager::canViewForRecord($loan, EditLoan::class))->toBeFalse();
});

test('approved loan awaiting disbursement shows disburse action on edit page', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'approved',
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'approved_at' => now(),
    ]);

    Livewire::test(EditLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful()
        ->assertSee(__('Disburse loan #:id', ['id' => $loan->getKey()]), false)
        ->assertActionVisible('disburse');
});
