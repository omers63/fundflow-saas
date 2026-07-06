<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ViewMember;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Member::query()->delete();
    User::query()->delete();
});

test('view member workspace shell renders inline summary without insights widget', function () {
    $admin = User::create([
        'name' => 'Performance Admin',
        'email' => 'perf-view-member@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-PERF',
        'name' => 'Performance Member',
        'email' => 'perf-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('ff-member-workspace-summary', false)
        ->assertDontSee('ff-member-detail-shell', false)
        ->assertDontSee('ff-app-insights-kpi-strip', false);
});
