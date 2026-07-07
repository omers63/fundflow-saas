<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\MemberMembershipProfileService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->member = Member::create([
        'member_number' => 'MEM-PROF01',
        'name' => 'Profile Member',
        'email' => 'profile-member@fund.test',
        'phone' => '0500000001',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->memberUser = User::create([
        'name' => $this->member->name,
        'email' => $this->member->email,
        'phone' => $this->member->phone,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $this->member->update(['user_id' => $this->memberUser->id]);
    $this->member = $this->member->fresh();
});

test('settings account tab renders membership application profile sections', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->assertSet('activeTab', 'profile')
        ->assertFormFieldExists('national_id')
        ->assertFormFieldExists('iban')
        ->assertFormFieldExists('application_form_path')
        ->assertSuccessful();
});

test('member can save membership application profile fields from settings', function () {
    MembershipApplication::factory()->approved()->create([
        'member_id' => $this->member->id,
        'name' => $this->member->name,
        'email' => $this->member->email,
        'mobile_phone' => $this->member->phone,
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->fillForm([
            'national_id' => '1234567890',
            'city' => 'Riyadh',
            'mobile_phone' => '0501234567',
            'iban' => 'sa03 8000 0000 6080 1016 7519',
        ])
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertNotified();

    $application = app(MemberMembershipProfileService::class)->findForMember($this->member->fresh());

    expect($application)->not->toBeNull()
        ->and($application->national_id)->toBe('1234567890')
        ->and($application->city)->toBe('Riyadh')
        ->and($application->iban)->toBe('SA03 8000 0000 6080 1016 7519')
        ->and($this->memberUser->fresh()->phone)->toBe('0501234567')
        ->and($this->member->fresh()->phone)->toBe('0501234567');
});

test('member without application record gets one created on profile save', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    expect(app(MemberMembershipProfileService::class)->findForMember($this->member))->toBeNull();

    Livewire::test(MemberSettingsPage::class)
        ->fillForm([
            'occupation' => 'Engineer',
            'employer' => 'Acme Corp',
        ])
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertNotified();

    $application = app(MemberMembershipProfileService::class)->findForMember($this->member->fresh());

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('approved')
        ->and($application->occupation)->toBe('Engineer')
        ->and($application->employer)->toBe('Acme Corp');
});
