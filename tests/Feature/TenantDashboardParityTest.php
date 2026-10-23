<?php

use App\Filament\Tenant\Widgets\FundLifetimeTrendWidget;
use App\Filament\Tenant\Widgets\TenantDashboardWidget;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

it('renders the consolidated admin dashboard (PHP + Nest blocks)', function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    User::query()->delete();
    $admin = User::create([
        'name' => 'Dash Admin', 'email' => 'dash-admin@fund.test', 'password' => bcrypt('password'),
        'email_verified_at' => now(), 'is_admin' => true, 'preferred_locale' => 'en',
    ]);
    $this->actingAs($admin, 'tenant');

    Livewire::test(TenantDashboardWidget::class)
        ->call('unfoldSection', 'analytics')
        ->assertSee(__('Recon exceptions'))
        ->assertSee(__('Overdue EMIs'))
        ->assertSee(__('Master pool drift'))
        ->assertSee(__('Quick actions'))
        ->assertSee(__('Open items'))
        ->assertSee(__('Forecast summary'))
        ->assertSee(__('Lifetime fund activity'))
        ->assertSee(__('Collection flow (30d)'))
        ->assertSee(__('Pool composition'))
        ->assertSee(__('Attention queue'))
        ->assertSee(__('Recent members'))
        ->assertSee(__('Loan pipeline'));

    Livewire::test(FundLifetimeTrendWidget::class)->assertSee('Fund contributions', false);
});
