<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ViewLoan;
use App\Filament\Tenant\Resources\Members\MemberResource;
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
        ->assertSee(__('Application & purpose'), false)
        ->assertSee(__('Details'), false);
});

test('loan view page subheading links member with number and omits guarantor', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'name' => 'Borrower One',
        'member_number' => 'M-100',
    ]);
    $guarantor = Member::factory()->create([
        'status' => 'active',
        'name' => 'Guarantor One',
        'member_number' => 'M-200',
    ]);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'guarantor_member_id' => $guarantor->id,
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'amount_disbursed' => 12000,
        'disbursed_at' => now(),
    ]);

    $component = Livewire::test(ViewLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful();

    $subheading = (string) $component->instance()->getSubheading();

    expect($subheading)
        ->toContain('Borrower One (M-100)')
        ->toContain(MemberResource::getUrl('view', ['record' => $member]))
        ->not->toContain('Guarantor One')
        ->not->toContain(e(__('Guarantor')));
});

test('loan view page header actions are icon buttons', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'approved',
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'approved_at' => now(),
    ]);

    $component = Livewire::test(ViewLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful();

    $headerActions = $component->instance()->getCachedHeaderActions();

    expect($headerActions)->not->toBeEmpty();

    foreach ($headerActions as $action) {
        expect($action->isIconButton())->toBeTrue()
            ->and($action->getTooltip())->not->toBeEmpty();
    }

    $names = collect($headerActions)->map(fn ($action) => $action->getName())->all();

    expect($names)
        ->not->toContain('transferLoanAdmin')
        ->not->toContain('transferGuarantorLiability')
        ->not->toContain('restoreBorrowerLiability');
});
