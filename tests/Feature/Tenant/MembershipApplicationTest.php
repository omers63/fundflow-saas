<?php

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\CreateMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\EditMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\MembershipApplicationApprovalService;
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

    MembershipApplication::create([
        'name' => 'Widget Pending',
        'email' => 'widget-pending@test.com',
        'application_type' => 'new',
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ListMembershipApplications::class)
        ->assertSuccessful()
        ->assertSee(__('Review new membership applications, track approval rates, and manage the onboarding pipeline.'))
        ->assertSee(__('Import Applications'))
        ->assertSee(__('New Application'))
        ->assertSee(__('Applications need your attention'))
        ->assertSee(__('Pipeline'))
        ->assertSee(__('Review queue'));
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

    $result = app(MembershipApplicationImportService::class)->import($path, 'DefaultPass1', '2024-06-01');

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $application = MembershipApplication::query()->where('email', 'csv.applicant@example.test')->first();

    expect($application)->not->toBeNull()
        ->and($application->status)->toBe('pending')
        ->and($application->name)->toBe('CSV Applicant');

    @unlink($path);
});

test('import links rows with the same email so the first row is the household parent application', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $csv = implode("\n", [
        'name,email,mobile_phone,iban',
        'Parent Applicant,household@example.test,0501000101,SA030000000000101000000101',
        'Dependent One,household@example.test,0501000102,SA030000000000101000000102',
        'Dependent Two,household@example.test,0501000103,SA030000000000101000000103',
    ]);

    $path = storage_path('app/testing-household-import.csv');
    file_put_contents($path, $csv);

    $this->actingAs($admin, 'tenant');

    $result = app(MembershipApplicationImportService::class)->import($path, 'DefaultPass1', '2024-06-01');

    expect($result['created'])->toBe(3)
        ->and($result['failed'])->toBe(0);

    $applications = MembershipApplication::query()
        ->where('household_email', 'household@example.test')
        ->orderBy('id')
        ->get();

    expect($applications)->toHaveCount(3)
        ->and($applications[0]->parent_application_id)->toBeNull()
        ->and($applications[1]->parent_application_id)->toBe($applications[0]->id)
        ->and($applications[2]->parent_application_id)->toBe($applications[0]->id);

    @unlink($path);
});

test('approving imported household applications creates one parent member and linked dependents', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $csv = implode("\n", [
        'name,email,mobile_phone,iban',
        'Parent Applicant,household@example.test,0501000101,SA030000000000101000000101',
        'Dependent One,household@example.test,0501000102,SA030000000000101000000102',
    ]);

    $path = storage_path('app/testing-household-approve.csv');
    file_put_contents($path, $csv);

    $this->actingAs($admin, 'tenant');

    app(MembershipApplicationImportService::class)->import($path, 'HouseholdPass1');

    $applications = MembershipApplication::query()
        ->where('household_email', 'household@example.test')
        ->orderBy('id')
        ->get();

    $approval = app(MembershipApplicationApprovalService::class);
    $approval->approveMany($applications->reverse())['members'];

    $parentMember = Member::query()
        ->where('name', 'Parent Applicant')
        ->whereNull('parent_member_id')
        ->first();

    $dependentMember = Member::query()
        ->where('name', 'Dependent One')
        ->first();

    expect($parentMember)->not->toBeNull()
        ->and($dependentMember)->not->toBeNull()
        ->and($dependentMember->parent_member_id)->toBe($parentMember->id)
        ->and($dependentMember->user_id)->not->toBe($parentMember->user_id)
        ->and($dependentMember->household_email)->toBe('household@example.test')
        ->and($dependentMember->is_separated)->toBeFalse()
        ->and($dependentMember->direct_login_enabled)->toBeFalse()
        ->and(User::query()->where('email', 'household@example.test')->count())->toBe(1);

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

test('membership application form upload notice view includes legacy instructions', function () {
    $html = view('filament.tenant.membership-application-form-upload-notice', [
        'downloadUrl' => 'https://example.com/template.pdf',
    ])->render();

    expect($html)
        ->toContain('Please upload a readable scan or photo of your')
        ->toContain('https://example.com/template.pdf')
        ->toContain('Download blank form template (PDF)');
});

test('membership application form upload notice view omits download link when url is empty', function () {
    $html = view('filament.tenant.membership-application-form-upload-notice', [
        'downloadUrl' => null,
    ])->render();

    expect($html)
        ->toContain('Please upload a readable scan or photo of your')
        ->not->toContain('Download blank form template (PDF)');
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

test('admin can delete membership application from applications table', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-delete-app@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $application = MembershipApplication::create([
        'name' => 'To Delete',
        'email' => 'delete-me@example.com',
        'password' => bcrypt('secret'),
        'application_type' => 'new',
        'status' => 'rejected',
        'reviewed_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ListMembershipApplications::class)
        ->callTableAction('delete', $application)
        ->assertNotified();

    expect(MembershipApplication::query()->find($application->id))->toBeNull();
});

test('admin can bulk delete membership applications from applications table', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-bulk-delete-app@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $first = MembershipApplication::create([
        'name' => 'Delete One',
        'email' => 'delete-one@example.com',
        'password' => bcrypt('secret'),
        'application_type' => 'new',
        'status' => 'pending',
    ]);

    $second = MembershipApplication::create([
        'name' => 'Delete Two',
        'email' => 'delete-two@example.com',
        'password' => bcrypt('secret'),
        'application_type' => 'renew',
        'status' => 'rejected',
        'reviewed_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ListMembershipApplications::class)
        ->callTableBulkAction('delete', [$first, $second])
        ->assertNotified();

    expect(MembershipApplication::query()->whereKey([$first->id, $second->id])->count())->toBe(0);
});
