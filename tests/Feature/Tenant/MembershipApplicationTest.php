<?php

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\CreateMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\EditMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Models\Central\Tenant;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\MembershipApplicationImportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    MembershipApplication::query()->delete();
});

test('membership application can be created', function () {
    $application = MembershipApplication::create([
        'name' => 'John Applicant',
        'email' => 'john@example.com',
        'phone' => '+1234567890',
        'application_type' => 'new',
        'message' => 'I would like to join',
        'status' => 'pending',
    ]);

    expect($application->exists)->toBeTrue();
    expect($application->status)->toBe('pending');
    expect($application->name)->toBe('John Applicant');
});

test('pending scope filters correctly', function () {
    MembershipApplication::create([
        'name' => 'Pending',
        'email' => 'p@test.com',
        'application_type' => 'new',
        'status' => 'pending',
    ]);
    MembershipApplication::create([
        'name' => 'Approved',
        'email' => 'a@test.com',
        'application_type' => 'renew',
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);

    expect(MembershipApplication::pending()->count())->toBe(1);
    expect(MembershipApplication::approved()->count())->toBe(1);
});

test('applications navigation badge shows pending count', function () {
    MembershipApplication::create([
        'name' => 'Pending One',
        'email' => 'one@test.com',
        'application_type' => 'new',
        'status' => 'pending',
    ]);
    MembershipApplication::create([
        'name' => 'Pending Two',
        'email' => 'two@test.com',
        'application_type' => 'new',
        'status' => 'pending',
    ]);
    MembershipApplication::create([
        'name' => 'Approved',
        'email' => 'approved@test.com',
        'application_type' => 'new',
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);

    expect(MembershipApplicationResource::getNavigationBadge())->toBe('2');
    expect(MembershipApplicationResource::getNavigationBadgeColor())->toBe('warning');
});

test('applications navigation badge is hidden when there are no pending applications', function () {
    MembershipApplication::create([
        'name' => 'Approved',
        'email' => 'approved@test.com',
        'application_type' => 'new',
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);

    expect(MembershipApplicationResource::getNavigationBadge())->toBeNull();
});

test('applications list shows purpose subheading and header actions for admin', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ListMembershipApplications::class)
        ->assertSuccessful()
        ->assertSee(__('Review new membership applications, track approval rates, and manage the onboarding pipeline.'))
        ->assertSee(__('Import Applications'))
        ->assertSee(__('New Application'));
});

test('admin can create application from tenant panel', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(CreateMembershipApplication::class)
        ->fillForm([
            'name' => 'New Applicant',
            'email' => 'new.applicant@example.test',
            'password' => 'SecurePass1',
            'password_confirmation' => 'SecurePass1',
            'application_type' => 'new',
            'mobile_phone' => '0501000999',
            'iban' => 'SA030000000000101000000099',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $application = MembershipApplication::query()->where('email', 'new.applicant@example.test')->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->name)->toBe('New Applicant')
        ->and($application->mobile_phone)->toBe('0501000999');
});

test('membership application import service creates pending applications from csv', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $csv = implode("\n", [
        'name,email,mobile_phone,iban,password',
        'CSV Applicant,csv.applicant@example.test,0501000888,SA030000000000101000000088,TempPass@88',
    ]);

    $path = storage_path('app/testing-membership-import.csv');
    file_put_contents($path, $csv);

    $this->actingAs($admin, 'tenant');

    $result = app(MembershipApplicationImportService::class)->import($path, 'DefaultPass1');

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $application = MembershipApplication::query()->where('email', 'csv.applicant@example.test')->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->name)->toBe('CSV Applicant');

    @unlink($path);
});

test('tenant membership application import sample download is available', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get('http://'.$domain.'/downloads/membership-application-import-sample');

    $response->assertOk();
    $response->assertDownload('membership-applications-sample-20.csv');
});

test('admin can open application edit form with full profile fields', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $application = MembershipApplication::create([
        'name' => 'Jane Applicant',
        'email' => 'jane@example.com',
        'password' => bcrypt('secret'),
        'application_type' => 'new',
        'national_id' => '1234567890',
        'date_of_birth' => '1990-01-15',
        'address' => '123 Main St',
        'city' => 'Riyadh',
        'mobile_phone' => '+966500000000',
        'bank_account_number' => '12345',
        'iban' => 'SA1234567890123456789012',
        'status' => 'pending',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(EditMembershipApplication::class, ['record' => $application->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet([
            'name' => 'Jane Applicant',
            'email' => 'jane@example.com',
            'national_id' => '1234567890',
            'city' => 'Riyadh',
        ]);
});
