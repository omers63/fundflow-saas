<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();

    $this->parentUser = User::create([
        'name' => 'Parent User',
        'email' => 'family@fund.test',
        'password' => 'ParentPass123',
        'is_admin' => false,
    ]);

    $this->parent = Member::create([
        'user_id' => $this->parentUser->id,
        'member_number' => 'MEM-P001',
        'name' => 'Parent User',
        'email' => 'family@fund.test',
        'household_email' => 'family@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->parent);

    $this->dependentUser = User::create([
        'name' => 'Dependent User',
        'email' => 'dependent.old@fund.test',
        'password' => 'OldPass123',
        'is_admin' => false,
    ]);

    $this->dependent = Member::create([
        'user_id' => $this->dependentUser->id,
        'parent_member_id' => $this->parent->id,
        'member_number' => 'MEM-D001',
        'name' => 'Dependent User',
        'email' => 'dependent.old@fund.test',
        'household_email' => 'family@fund.test',
        'is_separated' => true,
        'direct_login_enabled' => true,
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->dependent);
});

test('edit profile saves new password from dehydrated false fields', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->fillForm([
            'current_password' => 'OldPass123',
            'new_password' => 'Password1',
            'new_password_confirmation' => 'Password1',
        ])
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertNotified();

    $hash = User::query()->whereKey($this->dependentUser->id)->value('password');

    expect(Hash::check('Password1', (string) $hash))->toBeTrue()
        ->and(Hash::check('OldPass123', (string) $hash))->toBeFalse();
});
