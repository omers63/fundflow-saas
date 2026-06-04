<?php

use App\Filament\Member\Pages\MyProfilePage;
use App\Livewire\Tenant\MemberLoginPage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\ImpersonationService;
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

test('member without dependents logs in directly', function () {
    $this->dependent->delete();
    $this->dependentUser->delete();

    Livewire::test(MemberLoginPage::class)
        ->set('email', 'family@fund.test')
        ->set('password', 'ParentPass123')
        ->call('login')
        ->assertRedirect('/member');
});

test('parent can access my profile page', function () {
    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(MyProfilePage::class)
        ->assertSuccessful()
        ->assertSee('Household profiles')
        ->assertSee('Parent User')
        ->assertSee('Dependent User');
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
