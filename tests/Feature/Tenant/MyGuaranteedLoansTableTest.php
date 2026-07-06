<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyGuaranteedLoans\Pages\ListMyGuaranteedLoans;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    Loan::query()->delete();
    User::query()->delete();

    $this->guarantor = Member::create([
        'member_number' => 'MEM-GUAR-LIST',
        'name' => 'Guarantor List Member',
        'email' => 'guarantor-list@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->borrower = Member::create([
        'member_number' => 'MEM-BOR-LIST',
        'name' => 'Borrower List Member',
        'email' => 'borrower-list@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->guarantor);
    app(AccountingService::class)->createMemberAccounts($this->borrower);

    $this->guarantorUser = User::create([
        'name' => $this->guarantor->name,
        'email' => $this->guarantor->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->guarantor->update(['user_id' => $this->guarantorUser->id]);

    $this->guaranteedLoan = Loan::factory()->for($this->borrower)->create([
        'guarantor_member_id' => $this->guarantor->id,
        'status' => 'active',
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
    ]);
});

test('guaranteed loans resource is read only for members', function () {
    expect(MyGuaranteedLoanResource::canCreate())->toBeFalse()
        ->and(MyGuaranteedLoanResource::canEdit($this->guaranteedLoan))->toBeFalse()
        ->and(MyGuaranteedLoanResource::canDelete($this->guaranteedLoan))->toBeFalse();
});

test('guaranteed loans list table has no row navigation or mutating actions', function () {
    $this->actingAs($this->guarantorUser, 'tenant');

    Livewire::test(ListMyGuaranteedLoans::class)
        ->assertSuccessful()
        ->assertSee('Borrower List Member')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('view');

    $component = Livewire::test(ListMyGuaranteedLoans::class);

    expect($component->instance()->getTable()->getRecordUrl($this->guaranteedLoan))
        ->toBeNull();
});
