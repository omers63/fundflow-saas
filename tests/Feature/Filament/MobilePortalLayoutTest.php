<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyContributions\Pages\ListMyContributions;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

test('tenant tables stack on mobile by default', function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Mobile Table Admin',
        'email' => 'mobile-table-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(ListMembers::class);

    expect($component->instance()->getTable()->isStackedOnMobile())->toBeTrue();
});

test('member tables stack on mobile by default', function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    $user = User::create([
        'name' => 'Mobile Table Member',
        'email' => 'mobile-table-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MOB-001',
        'name' => 'Mobile Table Member',
        'email' => 'mobile-table-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $component = Livewire::actingAs($user, 'tenant')
        ->test(ListMyContributions::class);

    expect($component->instance()->getTable()->isStackedOnMobile())->toBeTrue();
});

test('mobile portal layout stylesheet is bundled with filament themes', function (): void {
    $paths = [
        base_path('resources/css/filament/mobile-panels.css'),
        base_path('resources/css/filament/mobile-portal-layout.css'),
        base_path('resources/css/filament/admin/theme.css'),
        base_path('resources/css/filament/tenant/theme.css'),
        base_path('resources/css/filament/member/theme.css'),
    ];

    foreach ($paths as $path) {
        expect(file_exists($path))->toBeTrue($path);
    }

    $mobilePanels = file_get_contents($paths[0]);

    expect($mobilePanels)->toContain("@import './mobile-portal-layout.css'");

    foreach (array_slice($paths, 2) as $themePath) {
        expect(file_get_contents($themePath))->toContain("@import '../mobile-panels.css'");
    }
});
