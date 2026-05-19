<?php

use App\Livewire\Tenant\MembershipEnrollmentWizard;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Support\PublicPageSettings;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;
use Tests\Concerns\ProvidesMembershipEnrollmentCredentials;

uses(InitializesTenancy::class, ProvidesMembershipEnrollmentCredentials::class);

beforeEach(function () {
    $this->initializeTenancy();

    MembershipApplication::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    PublicPageSettings::save(PublicPageSettings::defaults());
});

test('membership wizard shows download application form button when document url is configured', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'membership_application_document_url' => 'https://example.com/membership-form.pdf',
    ]);

    Livewire::test(MembershipEnrollmentWizard::class)
        ->assertSee(__('Download Membership Application Form (PDF)'), false)
        ->assertSee('https://example.com/membership-form.pdf', false);
});

test('membership enrollment page binds livewire root around navigation buttons', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $html = $this->get('http://' . $domain . '/membership')->assertSuccessful()->getContent();

    expect($html)->toMatch('/wire:id="[^"]+"[^>]*class="membership-enrollment-wizard"/');
    expect($html)->toContain('wire:click="nextStep"');
});

test('membership enrollment page is available on the tenant domain', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '/membership')
        ->assertSuccessful()
        ->assertSee(__('Apply for membership'), false);
});

test('apply route serves the same enrollment wizard', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://' . $domain . '/apply')
        ->assertSuccessful()
        ->assertSee(__('Information'), false)
        ->assertSee(__('Personal details'), false);
});

test('landing page links to membership enrollment instead of embedding the form', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get('http://' . $domain);

    $response->assertSuccessful();
    $response->assertSee(route('tenant.membership', absolute: false), false);
    $response->assertDontSee('membership-application-form', false);
});

test('membership wizard hides fees step when selected application type has no fee', function () {
    $component = Livewire::test(MembershipEnrollmentWizard::class)
        ->assertDontSee(__('Membership fees'), false);

    expect($component->instance()->lastStep())->toBe(4);
});

test('membership wizard shows fees step when selected application type has a fee', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
    ]);

    $component = Livewire::test(MembershipEnrollmentWizard::class)
        ->assertSee(__('Membership fees'), false);

    expect($component->instance()->lastStep())->toBe(5);
});

test('membership wizard advances from step one after selecting application type', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
        'fee_resume' => '50',
    ]);

    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'resume')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->set('applicationType', 'new')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('step', 2)
        ->assertSet('membership_fee_transfer_amount', '100.00');
});

test('membership wizard validates step one before advancing', function () {
    Livewire::test(MembershipEnrollmentWizard::class)
        ->call('nextStep')
        ->assertHasErrors(['name', 'email', 'password']);
});

test('membership wizard requires matching password confirmation on step one', function () {
    Livewire::test(MembershipEnrollmentWizard::class)
        ->set('applicationType', 'new')
        ->set('name', 'Jane Applicant')
        ->set('email', 'jane@example.com')
        ->set('password', 'SecurePass1!')
        ->set('password_confirmation', 'DifferentPass1!')
        ->call('nextStep')
        ->assertHasErrors(['password']);
});

test('membership wizard validates identity step before advancing', function () {
    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->assertSet('step', 2)
        ->call('nextStep')
        ->assertHasErrors(['national_id', 'date_of_birth', 'address', 'city', 'mobile_phone', 'bank_account_number', 'iban']);
});

test('membership wizard shows identity fields on step two', function () {
    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->assertSee(__('National ID'), false)
        ->assertSee(__('Mobile phone'), false)
        ->assertDontSee(__('Password'), false);
});

test('membership wizard shows fee transfer bank details on fees step when fee applies', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
        'fee_transfer_bank_name' => 'Test Bank',
        'fee_transfer_iban' => 'SA0380000000608010167519',
    ]);

    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->call('nextStep')
        ->assertSet('step', 5)
        ->assertSee(__('Membership fees'), false)
        ->assertSee(__('Bank transfer details'), false)
        ->assertSee('Test Bank', false)
        ->assertSee('SA0380000000608010167519', false);
});

test('membership wizard stores a hashed password and profile on the application', function () {
    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->call('nextStep')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSee('tenant-centered-page', false);

    $application = MembershipApplication::query()->first();

    expect($application)->not->toBeNull()
        ->and(Hash::check('SecurePass1!', $application->password))->toBeTrue()
        ->and($application->national_id)->toBe('1234567890')
        ->and($application->mobile_phone)->toBe('+966501234567')
        ->and($application->next_of_kin_name)->toBe('Mohammed Example');
});

test('membership wizard does not require next of kin', function () {
    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'resume')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test, withNextOfKin: false)->call('nextStep')->call('nextStep'))
        ->assertSet('step', 4)
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSee('tenant-centered-page', false);

    $application = MembershipApplication::query()->first();

    expect($application)->not->toBeNull()
        ->and($application->next_of_kin_name)->toBeNull()
        ->and($application->next_of_kin_phone)->toBeNull();
});

test('membership wizard submits from document step when no fee applies', function () {
    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'resume')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->assertSet('step', 4)
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSee('tenant-centered-page', false);

    $application = MembershipApplication::query()->first();

    expect($application)->not->toBeNull()
        ->and($application->application_type)->toBe('resume')
        ->and($application->name)->toBe('Jane Applicant')
        ->and($application->membership_fee_amount)->toBeNull();
});

test('membership wizard submits from fees step when a fee applies', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_resume' => '50',
        'fee_transfer_bank_name' => 'Test Bank',
        'fee_transfer_iban' => 'SA0380000000608010167519',
    ]);

    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'resume')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->call('nextStep')
        ->assertSet('step', 5)
        ->tap(fn($test) => $this->withEnrollmentFeePayment($test))
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSee('tenant-centered-page', false);

    $application = MembershipApplication::query()->first();

    expect($application)->not->toBeNull()
        ->and((float) $application->membership_fee_amount)->toBe(50.0)
        ->and((float) $application->membership_fee_required_amount)->toBe(50.0)
        ->and($application->membership_fee_transfer_date?->toDateString())->toBe(now()->toDateString())
        ->and($application->membership_fee_transfer_reference)->toBe('TXN-REF-12345');
});

test('membership wizard defaults transfer amount from application type fee', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
        'fee_resume' => '50',
        'fee_renew' => '75',
    ]);

    $component = Livewire::test(MembershipEnrollmentWizard::class)
        ->assertSet('membership_fee_transfer_date', now()->toDateString())
        ->assertSet('membership_fee_transfer_amount', '100.00');

    $component->set('applicationType', 'renew')
        ->assertSet('membership_fee_transfer_amount', '75.00');
});

test('membership wizard requires transfer date and amount on fees step', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
    ]);

    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->call('nextStep')
        ->set('membership_fee_transfer_date', '')
        ->set('membership_fee_transfer_amount', '')
        ->set('membership_fee_transfer_reference', 'TXN-REF')
        ->call('submit')
        ->assertHasErrors(['membership_fee_transfer_date', 'membership_fee_transfer_amount']);
});

test('membership wizard requires fee acknowledgment when fee applies', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fee_new' => '100',
    ]);

    $this->withEnrollmentPassword(
        Livewire::test(MembershipEnrollmentWizard::class)
            ->set('applicationType', 'new')
            ->set('name', 'Jane Applicant')
            ->set('email', 'jane@example.com')
    )
        ->call('nextStep')
        ->tap(fn($test) => $this->withEnrollmentProfile($test)->call('nextStep')->call('nextStep'))
        ->call('nextStep')
        ->set('membership_fee_transfer_reference', 'TXN-REF')
        ->call('submit')
        ->assertHasErrors(['membership_fee_acknowledged']);
});

test('step one shows password fields', function () {
    Livewire::test(MembershipEnrollmentWizard::class)
        ->assertSee(__('Password'), false)
        ->assertSee(__('Confirm password'), false)
        ->assertDontSee(__('National ID'), false);
});
