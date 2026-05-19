<?php

use App\Livewire\Tenant\ApplicationStatusPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\MembershipApplication;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    MembershipApplication::query()->delete();
});

test('application status page is available on the tenant domain', function () {
    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $this->get('http://'.$domain.'/application-status')
        ->assertSuccessful()
        ->assertSee(__('Check Application Status'), false)
        ->assertSee('tenant-centered-page', false);
});

test('application status lookup returns pending application details', function () {
    MembershipApplication::factory()->create([
        'name' => 'Jane Applicant',
        'email' => 'jane@example.com',
        'national_id' => '1234567890',
        'city' => 'Riyadh',
        'status' => 'pending',
    ]);

    Livewire::test(ApplicationStatusPage::class)
        ->set('email', 'jane@example.com')
        ->set('national_id', '1234567890')
        ->call('check')
        ->assertSet('searched', true)
        ->assertSee(__('Pending review'), false)
        ->assertSee('Jane Applicant', false)
        ->assertSee('Riyadh', false);
});

test('application status lookup shows not found when no match', function () {
    Livewire::test(ApplicationStatusPage::class)
        ->set('email', 'unknown@example.com')
        ->set('national_id', '9999999999')
        ->call('check')
        ->assertSet('searched', true)
        ->assertSet('result', null)
        ->assertSee(__('No application found'), false);
});

test('application status lookup shows approved state', function () {
    MembershipApplication::factory()->create([
        'name' => 'Jane Applicant',
        'email' => 'jane@example.com',
        'national_id' => '1234567890',
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);

    Livewire::test(ApplicationStatusPage::class)
        ->set('email', 'jane@example.com')
        ->set('national_id', '1234567890')
        ->call('check')
        ->assertSee(__('Approved!'), false)
        ->assertSee(__('Sign in to your account'), false);
});
