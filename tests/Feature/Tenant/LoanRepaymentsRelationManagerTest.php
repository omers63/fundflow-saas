<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
use App\Filament\Tenant\Resources\Loans\RelationManagers\RepaymentsRelationManager;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('imported legacy repayments tab is hidden when loan has no legacy rows', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create();

    expect(RepaymentsRelationManager::canViewForRecord($loan, ViewLoan::class))->toBeFalse();
});

test('imported legacy repayments tab is visible when loan has legacy rows', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create();

    $loan->repayments()->create([
        'amount' => 1000,
        'paid_at' => now(),
        'notes' => 'Imported',
    ]);

    expect(RepaymentsRelationManager::canViewForRecord($loan->fresh(), ViewLoan::class))->toBeTrue();
});

test('imported legacy repayments relation manager renders early settle header without error', function () {
    Filament::setCurrentPanel('tenant');

    $member = Member::factory()->create(['status' => 'active']);
    $loan = Loan::factory()->for($member)->create(['status' => 'active']);

    $loan->repayments()->create([
        'amount' => 1000,
        'paid_at' => now(),
        'notes' => 'Imported',
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
        ->assertSee(__('Early settlement'));
});
