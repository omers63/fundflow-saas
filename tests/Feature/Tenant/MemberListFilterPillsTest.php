<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\MemberListTabService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');

    if ($tenant !== null && ! $tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
    }

    $this->actingAs(User::create([
        'name' => 'Member List Admin',
        'email' => 'member-list-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('member list tab service includes migration pending in pill tabs', function () {
    $tabs = app(MemberListTabService::class)->pillTabs()->pluck('key')->all();

    expect($tabs)->toBe(['all', 'active', 'migration_pending', 'delinquent', 'suspended']);
});

test('migration pending tab matches imported members with contribution arrears', function () {
    $migratedWithArrears = Member::factory()->create([
        'status' => 'active',
        'opening_balances_posted_at' => now(),
        'contribution_arrears_cutoff_date' => now()->subMonths(3)->startOfMonth(),
        'joined_at' => now()->subMonths(6),
        'monthly_contribution_amount' => 500,
    ]);

    Member::factory()->create([
        'status' => 'active',
        'opening_balances_posted_at' => now(),
        'joined_at' => now()->subMonth(),
        'monthly_contribution_amount' => 500,
    ]);

    $ids = app(MemberListTabService::class)->migrationPendingMemberIds();

    expect($ids)->toContain($migratedWithArrears->id);
});

test('member delinquent tab url uses tab query parameter', function () {
    expect(MemberResource::listTabUrl('delinquent'))
        ->toContain('tab=delinquent')
        ->not->toContain('tableFilters');
});

test('member migration pending tab url uses tab query parameter', function () {
    expect(MemberResource::listTabUrl('migration_pending'))
        ->toContain('tab=migration_pending');
});

test('disbursements and reports pages are accessible to tenant admins', function () {
    expect(DisbursementsPage::canAccess())->toBeTrue()
        ->and(ReportsPage::canAccess())->toBeTrue();
});

test('list members page exposes status filter pill wrapper content', function () {
    Livewire::test(ListMembers::class)
        ->assertOk()
        ->assertSee(__('All'))
        ->assertSee(__('Migration pending'));
});
