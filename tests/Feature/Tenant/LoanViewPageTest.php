<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
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

    $admin = User::create([
        'name' => 'Loan View Admin',
        'email' => 'loan-view-page@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('loan view page shows lifecycle insights and detail sections', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'approved',
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'purpose' => 'Home renovation',
        'witness1_name' => 'Witness One',
        'witness1_phone' => '+966500000001',
        'approved_at' => now(),
    ]);

    Livewire::test(ViewLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful()
        ->assertSee('Home renovation', false)
        ->assertSee('Witness One', false)
        ->assertSeeHtml('ff-loan-detail-shell')
        ->assertSeeHtml('ff-loan-stepper')
        ->assertSee(__('Application & purpose'), false)
        ->assertSee(__('Details'), false);
});
