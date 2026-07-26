<?php

declare(strict_types=1);

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Delinquency Tabs Admin',
        'email' => 'delinquency-tabs-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

it('switches delinquency side tabs via setSideTab', function () {
    Livewire::test(DelinquencyWorkspacePage::class)
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'overdue')
        ->assertSet('sideTab', 'overdue')
        ->assertSuccessful()
        ->call('setSideTab', 'guarantor')
        ->assertSet('sideTab', 'guarantor')
        ->assertSuccessful()
        ->call('setSideTab', 'policy')
        ->assertSet('sideTab', 'policy')
        ->assertSuccessful()
        ->call('setSideTab', 'related')
        ->assertSet('sideTab', 'related')
        ->assertSuccessful()
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->assertSuccessful();
});

it('renders delinquency tab pills as navigable links', function () {
    $html = Livewire::test(DelinquencyWorkspacePage::class)->html();

    foreach (array_keys(DelinquencyTabRegistry::tabs()) as $tab) {
        expect($html)->toContain(DelinquencyTabRegistry::url($tab));
    }

    expect($html)
        ->toContain('ff-tenant-tab-pills__item no-underline')
        ->not->toContain('wire:click="setSideTab');
});

it('exposes admin loan transfer on overdue and guarantor liability on guarantor', function () {
    expect(collect(LoanFilamentActions::guarantorLiabilityActions())->map->getName()->all())
        ->toBe([
            'transferGuarantorLiability',
            'restoreBorrowerLiability',
        ]);

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue'])
        ->assertSuccessful()
        ->assertTableActionExists('transferLoanAdmin');

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'guarantor'])
        ->assertSuccessful()
        ->assertTableActionExists('transferGuarantorLiability')
        ->assertTableActionExists('restoreBorrowerLiability')
        ->assertTableActionDoesNotExist('transferLoanAdmin');
});

it('loads each delinquency panel from the sideTab query string', function () {
    foreach (DelinquencyTabRegistry::TABS as $tab) {
        Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => $tab])
            ->assertSet('sideTab', $tab)
            ->assertSuccessful();
    }
});
