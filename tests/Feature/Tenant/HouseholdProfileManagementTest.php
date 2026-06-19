<?php

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Livewire\Tenant\MemberLoginPage;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\Tenant\HouseholdAccessService;
use App\Services\Tenant\ImpersonationService;
use App\Support\MemberUserEmail;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Member::query()->delete();
    User::query()->delete();

    $this->parentUser = User::create([
        'name' => 'Parent User',
        'email' => 'family@fund.test',
        'password' => bcrypt('ParentPass123'),
        'is_admin' => false,
    ]);

    $this->parent = Member::create([
        'user_id' => $this->parentUser->id,
        'member_number' => 'MEM-H001',
        'name' => 'Parent User',
        'email' => 'family@fund.test',
        'household_email' => 'family@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
        'portal_pin' => bcrypt('1234'),
    ]);

    app(AccountingService::class)->createMemberAccounts($this->parent);

    $this->dependentUser = User::create([
        'name' => 'Dependent User',
        'email' => 'family@fund.test',
        'password' => bcrypt('DependentPass123'),
        'is_admin' => false,
    ]);

    $this->dependent = Member::create([
        'user_id' => $this->dependentUser->id,
        'parent_member_id' => $this->parent->id,
        'member_number' => 'MEM-H002',
        'name' => 'Dependent User',
        'email' => 'family@fund.test',
        'household_email' => 'family@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->dependent);
});

test('household login shows netflix style profile picker', function () {
    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertSet('showProfilePicker', true)
        ->assertSee('member-login-profile-grid', false)
        ->assertSee('Parent User')
        ->assertSee('Dependent User');
});

test('profile picker renders arabic names with rtl markup on english pages', function () {
    $this->parentUser->update(['name' => 'محمد أحمد']);
    $this->parent->update(['name' => 'محمد أحمد']);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertSee('ff-arabic-name', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee('<bdi dir="rtl" lang="ar" class="ff-arabic-name">محمد أحمد</bdi>', false);
});

test('parent can verify profile with pin and sign in', function () {
    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->call('selectProfile', $this->parent->id)
        ->set('verificationSecret', '1234')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->parentUser->id);
});

test('dependent can verify with password and sign in', function () {
    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->call('selectProfile', $this->dependent->id)
        ->set('verificationSecret', 'DependentPass123')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('separated dependent can verify with own password on household profile picker', function () {
    $this->dependent->update([
        'email' => 'dependent.unique@fund.test',
        'is_separated' => true,
        'direct_login_enabled' => true,
    ]);

    $this->dependentUser->update([
        'email' => 'dependent.unique@fund.test',
        'password' => 'SeparatedPass123',
    ]);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertSet('showProfilePicker', true)
        ->call('selectProfile', $this->dependent->id)
        ->set('verificationSecret', 'SeparatedPass123')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('household dependent with internal login email can verify with password on profile picker', function () {
    $internalEmail = app(MemberUserEmail::class)->generateInternalLoginEmail();

    $this->dependentUser->update(['email' => $internalEmail]);
    $this->dependent->update([
        'email' => 'family@fund.test',
        'is_separated' => false,
        'direct_login_enabled' => false,
    ]);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->call('selectProfile', $this->dependent->id)
        ->set('verificationSecret', 'DependentPass123')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('separated dependent approved from application can verify on household profile picker', function () {
    $parentApplication = MembershipApplication::create([
        'name' => 'Parent Applicant',
        'email' => 'household@example.test',
        'password' => 'HouseholdPass1',
        'application_type' => 'new',
        'mobile_phone' => '0501000101',
        'iban' => 'SA030000000000101000000101',
        'status' => 'pending',
        'household_email' => 'household@example.test',
    ]);

    $childApplication = MembershipApplication::create([
        'name' => 'Adult Child',
        'email' => 'adult.child@example.test',
        'password' => 'ChildPass123',
        'application_type' => 'new',
        'mobile_phone' => '0501000102',
        'iban' => 'SA030000000000101000000102',
        'status' => 'pending',
        'household_email' => 'household@example.test',
        'parent_application_id' => $parentApplication->id,
    ]);

    app(MembershipApplicationApprovalService::class)->approveMany(collect([$parentApplication, $childApplication]));

    $child = Member::query()->where('name', 'Adult Child')->firstOrFail();
    $childUser = $child->user;

    expect($child->is_separated)->toBeTrue()
        ->and(Hash::check('ChildPass123', (string) $childUser?->password))->toBeTrue();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'household@example.test')
        ->set('password', 'HouseholdPass1')
        ->call('login')
        ->assertSet('showProfilePicker', true)
        ->call('selectProfile', $child->id)
        ->set('verificationSecret', 'ChildPass123')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($childUser->id);
});

test('member without dependents logs in directly', function () {
    $this->dependent->delete();
    $this->dependentUser->delete();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertRedirect('/member');
});

test('parent can access household profiles on settings page', function () {
    Filament\Facades\Filament::setCurrentPanel('member');
    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->assertSuccessful()
        ->assertSee(__('Household profiles'))
        ->assertSee('Parent User')
        ->assertSee('Dependent User');
});

test('separated dependent can login directly after profile email and password change', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    app(HouseholdAccessService::class)->updateMemberLoginEmail(
        $this->dependent,
        $this->dependentUser,
        'separated.dependent@fund.test',
    );

    $this->dependentUser->refresh()->update(['password' => 'NewSeparatedPass123']);
    $this->dependent->refresh();

    expect($this->dependent->is_separated)->toBeTrue()
        ->and($this->dependent->direct_login_enabled)->toBeTrue()
        ->and($this->dependentUser->fresh()->email)->toBe('separated.dependent@fund.test');

    auth('tenant')->logout();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'separated.dependent@fund.test')
        ->set('password', 'NewSeparatedPass123')
        ->call('login')
        ->assertSet('showProfilePicker', false)
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('separated dependent can login via household profile picker after profile email and password change', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    app(HouseholdAccessService::class)->updateMemberLoginEmail(
        $this->dependent,
        $this->dependentUser,
        'separated.dependent@fund.test',
    );

    $this->dependentUser->refresh()->update(['password' => 'NewSeparatedPass123']);
    $this->dependent->refresh();

    auth('tenant')->logout();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertSet('showProfilePicker', true)
        ->call('selectProfile', $this->dependent->id)
        ->set('verificationSecret', 'NewSeparatedPass123')
        ->call('verifySelectedProfile')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('separated dependent direct login accepts mixed case email', function () {
    $this->dependent->update([
        'email' => 'separated.dependent@fund.test',
        'is_separated' => true,
        'direct_login_enabled' => true,
    ]);

    $this->dependentUser->update([
        'email' => 'separated.dependent@fund.test',
        'password' => 'NewSeparatedPass123',
    ]);

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'Separated.Dependent@Fund.test')
        ->set('password', 'NewSeparatedPass123')
        ->call('login')
        ->assertRedirect('/member');

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
});

test('impersonation switches to dependent and back on logout', function () {
    $this->actingAs($this->parentUser, 'tenant');

    app(ImpersonationService::class)->start($this->parentUser, $this->dependentUser, $this->dependent);

    expect(auth('tenant')->id())->toBe($this->dependentUser->id);
    expect(session('impersonator_user_id'))->toBe($this->parentUser->id);

    app(ImpersonationService::class)->stop();

    expect(auth('tenant')->id())->toBe($this->parentUser->id);
    expect(session('impersonator_user_id'))->toBeNull();
});
