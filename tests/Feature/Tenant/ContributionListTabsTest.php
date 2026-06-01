<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $admin = User::create([
        'name' => 'Contributions Admin',
        'email' => 'contributions-tabs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('contributions list has unified open cycle collect and collected tabs', function () {
    Livewire::test(ListContributions::class)
        ->assertSuccessful()
        ->assertSee(__('To collect'), false)
        ->assertSee(__('Collected'), false)
        ->assertSee(__('Ledger'), false);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'collect')
        ->assertSee(__('Cycle actions'), false)
        ->assertDontSee(__('Generate pending'), false);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertSee(__('Cycle actions'), false)
        ->assertSee(__('Generate pending'), false);

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'arrears')
        ->assertSee(__('Delinquency tools'), false)
        ->assertDontSee(__('Generate pending'), false);

    $url = ContributionResource::listTabUrl('collect');
    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/contributions';
    $query = parse_url($url, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('To collect'), false);
});

test('legacy contribution cycle route redirects to collect tab', function () {
    $legacyPath = parse_url(ContributionCyclePage::getUrl(), PHP_URL_PATH) ?? '/admin/contribution-cycle';
    $collectUrl = ContributionResource::listTabUrl('collect');
    $collectPath = parse_url($collectUrl, PHP_URL_PATH) ?? '/admin/contributions';
    $collectQuery = parse_url($collectUrl, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$legacyPath)
        ->assertRedirect($collectPath.($collectQuery ? '?'.$collectQuery : ''));
});
