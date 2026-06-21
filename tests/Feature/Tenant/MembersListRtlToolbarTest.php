<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    App::setLocale('ar');
});

test('members list renders arabic table header toolbar in rtl mode', function (): void {
    $admin = User::create([
        'name' => 'RTL Toolbar Admin',
        'email' => 'rtl-toolbar@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ListMembers::class)
        ->assertSuccessful()
        ->assertSee('fi-ta-header-toolbar', false)
        ->assertSee(__('Search', locale: 'ar'), false);
});
