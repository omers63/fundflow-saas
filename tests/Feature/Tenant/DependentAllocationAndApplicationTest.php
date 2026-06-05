<?php

use App\Filament\Member\Resources\MyDependents\Support\MyDependentTableActions;
use App\Livewire\Tenant\MembershipEnrollmentWizard;
use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\DependentAllocationService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MembershipEnrollmentService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $tenant = $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    $domain = 'testing.localhost';
    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }
    $this->tenantBaseUrl = 'http://'.$domain;

    Member::query()->delete();
    User::query()->delete();
    MembershipApplication::query()->delete();
    DependentAllocationChange::query()->delete();

    $this->parentUser = User::create([
        'name' => 'Parent User',
        'email' => 'parent@dependents.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->parent = Member::create([
        'user_id' => $this->parentUser->id,
        'member_number' => 'MEM-P001',
        'name' => 'Parent User',
        'email' => 'parent@dependents.test',
        'household_email' => 'parent@dependents.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->parent);

    $this->dependentUser = User::create([
        'name' => 'Child Member',
        'email' => 'child@household.members.local',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->dependent = Member::create([
        'user_id' => $this->dependentUser->id,
        'parent_member_id' => $this->parent->id,
        'member_number' => 'MEM-C001',
        'name' => 'Child Member',
        'email' => 'parent@dependents.test',
        'household_email' => 'parent@dependents.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->dependent);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('parent can update dependent allocation via service', function () {
    $change = app(DependentAllocationService::class)->changeAllocation(
        parent: $this->parent,
        dependent: $this->dependent,
        newAmount: 1000,
        note: 'Annual review',
        changedBy: $this->parentUser,
    );

    expect($change)->not->toBeNull()
        ->and($change->old_amount)->toBe(500)
        ->and($change->new_amount)->toBe(1000)
        ->and((int) $this->dependent->fresh()->monthly_contribution_amount)->toBe(1000);

    $this->assertDatabaseHas('dependent_allocation_changes', [
        'parent_member_id' => $this->parent->id,
        'dependent_member_id' => $this->dependent->id,
        'old_amount' => 500,
        'new_amount' => 1000,
    ]);
});

test('my dependents table defines apply and bulk allocation header actions', function () {
    $names = collect(MyDependentTableActions::headerActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toContain('apply_for_dependent', 'bulk_update_allocations');
});

test('parent can submit dependent application on behalf via enrollment service', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $application = app(MembershipEnrollmentService::class)->submitApplication([
        'name' => 'Dependent Applicant',
        'email' => 'parent@dependents.test',
        'password' => 'DependentPass123!',
        'application_type' => 'new',
        'national_id' => '1234567890',
        'date_of_birth' => now()->subYears(20)->toDateString(),
        'address' => 'Street 1',
        'city' => 'Riyadh',
        'mobile_phone' => '+966500000001',
        'bank_account_number' => '00112233',
        'iban' => 'SA0000000000000000000000',
        'next_of_kin_name' => 'Kin Name',
        'next_of_kin_phone' => '+966500000002',
        'application_form' => null,
        'membership_fee_amount' => 0,
        'membership_fee_receipt' => null,
        'parent_member_id' => $this->parent->id,
        'submitted_by_user_id' => $this->parentUser->id,
        'household_email' => 'parent@dependents.test',
    ]);

    expect($application->parent_member_id)->toBe($this->parent->id)
        ->and($application->submitted_by_user_id)->toBe($this->parentUser->id)
        ->and($application->household_email)->toBe('parent@dependents.test');
});

test('enrollment wizard enables on behalf mode for logged in parent', function () {
    $this->actingAs($this->parentUser, 'tenant');

    Livewire::withQueryParams(['on_behalf' => 1])
        ->test(MembershipEnrollmentWizard::class)
        ->assertSet('onBehalfMode', true)
        ->assertSet('parentMemberId', $this->parent->id)
        ->assertSet('email', 'parent@dependents.test');
});

test('approving on behalf application links new member to parent', function () {
    $application = MembershipApplication::create([
        'name' => 'New Dependent',
        'email' => 'parent@dependents.test',
        'household_email' => 'parent@dependents.test',
        'password' => 'DependentPass123!',
        'parent_member_id' => $this->parent->id,
        'submitted_by_user_id' => $this->parentUser->id,
        'application_type' => 'new',
        'national_id' => '1987654321',
        'date_of_birth' => now()->subYears(22)->toDateString(),
        'address' => 'Address',
        'city' => 'Riyadh',
        'mobile_phone' => '+966500000003',
        'bank_account_number' => '11223344',
        'iban' => 'SA0000000000000000000003',
        'next_of_kin_name' => 'Kin',
        'next_of_kin_phone' => '+966500000004',
        'status' => 'pending',
    ]);

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@dependents.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $member = app(MembershipApplicationApprovalService::class)->approve($application->fresh());

    expect($member->parent_member_id)->toBe($this->parent->id)
        ->and($member->household_email)->toBe('parent@dependents.test')
        ->and($member->is_separated)->toBeFalse()
        ->and($member->direct_login_enabled)->toBeFalse();
});
