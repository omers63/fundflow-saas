<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Pages\MigrationWorkflowPage;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\MigrationStubsRelationManager;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\User;
use App\Services\MigrationCycleService;
use App\Services\MigrationWorkflowService;
use App\Services\TenantDashboardService;
use App\Support\Lang;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Member::query()->delete();
    MigrationCycleStub::query()->delete();
});

test('migration workflow page registers and resolves url', function () {
    $admin = User::create([
        'name' => 'Migration Admin',
        'email' => 'migration-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    expect(MigrationWorkflowPage::shouldRegisterNavigation())->toBeTrue()
        ->and(MigrationWorkflowPage::getNavigationGroup())->toBe(TenantNavigation::GROUP_SYSTEM)
        ->and(MigrationWorkflowPage::getNavigationLabel())->toBe(Lang::ui('Migrations'))
        ->and(MigrationWorkflowPage::canAccess())->toBeTrue()
        ->and(MigrationWorkflowPage::getUrl())->toContain('/admin/migration-workflow')
        ->and(ContributionCyclePage::shouldRegisterNavigation())->toBeFalse();
});

test('migration workflow service queues pending members and open stubs', function () {
    $member = Member::create([
        'member_number' => 'MIG-WF-001',
        'name' => 'Workflow Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-02-01'),
    );

    $workflow = app(MigrationWorkflowService::class);

    expect($workflow->pendingMemberCount())->toBe(1)
        ->and($workflow->openStubCount())->toBeGreaterThan(0)
        ->and($workflow->pendingMembersQuery()->pluck('id'))->toContain($member->id);
});

test('tenant dashboard quick actions include migration workflow', function () {
    $user = User::create([
        'name' => 'Dash Admin',
        'email' => 'dash-mig@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($user, 'tenant');

    $labels = collect(app(TenantDashboardService::class)->snapshot()['quick_actions'])
        ->pluck('label')
        ->all();

    expect($labels)->toContain(Lang::ui('Migrations'));
});

test('members in migration tab exposes column manager filters and grouping', function () {
    $admin = User::create([
        'name' => 'Queue Table Admin',
        'email' => 'queue-table@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $member = Member::create([
        'member_number' => 'QUEUE-TAB-001',
        'name' => 'Queue Tab Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-02-01'),
    );

    $table = Livewire::test(MigrationWorkflowPage::class)
        ->assertSet('migrationWorkflowTab', 'queue')
        ->instance()
        ->getTable();

    expect($table->hasColumnManager())->toBeTrue()
        ->and($table->getFilters())->not->toBeEmpty()
        ->and($table->getGroups())->not->toBeEmpty();
});

test('not started tab exposes column manager filters and grouping', function () {
    $admin = User::create([
        'name' => 'Not Started Table Admin',
        'email' => 'not-started-table@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    Member::create([
        'member_number' => 'NOT-START-TAB-001',
        'name' => 'Not Started Tab Member',
        'monthly_contribution_amount' => 750,
        'joined_at' => Carbon::parse('2023-06-01'),
        'status' => 'active',
    ]);

    $table = Livewire::test(MigrationWorkflowPage::class)
        ->call('setMigrationTab', 'not_started')
        ->instance()
        ->getTable();

    expect($table->hasColumnManager())->toBeTrue()
        ->and($table->getFilters())->not->toBeEmpty()
        ->and($table->getGroups())->not->toBeEmpty();
});

test('open stubs tab exposes column manager filters and grouping', function () {
    $admin = User::create([
        'name' => 'Stubs Table Admin',
        'email' => 'stubs-table@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $member = Member::create([
        'member_number' => 'STUB-TAB-001',
        'name' => 'Stub Tab Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-02-01'),
    );

    $component = Livewire::test(MigrationWorkflowPage::class)
        ->call('setMigrationTab', 'stubs');

    $table = $component->instance()->getTable();

    expect($table->hasColumnManager())->toBeTrue()
        ->and($table->getFilters())->not->toBeEmpty()
        ->and($table->getGroups())->not->toBeEmpty();
});

test('not started tab shows member columns after switching from in migration tab', function () {
    $admin = User::create([
        'name' => 'Tab Switch Admin',
        'email' => 'tab-switch@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    Member::create([
        'member_number' => 'NOT-START-001',
        'name' => 'Not Started Yet',
        'email' => 'not-started@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2023-06-01'),
        'status' => 'active',
    ]);

    $pending = Member::create([
        'member_number' => 'PENDING-001',
        'name' => 'Pending Migrator',
        'email' => 'pending@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $pending,
        Carbon::parse('2024-02-01'),
    );

    $component = Livewire::test(MigrationWorkflowPage::class)
        ->assertSee('Pending Migrator')
        ->call('setMigrationTab', 'not_started')
        ->assertSet('migrationWorkflowTab', 'not_started');

    $names = $component->instance()->getTableRecords()->pluck('name')->all();

    expect($names)->toContain('Not Started Yet')
        ->not->toContain('Pending Migrator');
});

test('begin migration creates stubs for the chosen member and opens their migration cycles tab', function () {
    $admin = User::create([
        'name' => 'Begin Migration Admin',
        'email' => 'begin-migration@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $target = Member::create([
        'member_number' => 'BEGIN-TARGET-001',
        'name' => 'Target Migrator',
        'monthly_contribution_amount' => 800,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    $other = Member::create([
        'member_number' => 'BEGIN-OTHER-001',
        'name' => 'Other Not Started',
        'monthly_contribution_amount' => 600,
        'joined_at' => Carbon::parse('2023-01-01'),
        'status' => 'active',
    ]);

    Livewire::test(MigrationWorkflowPage::class)
        ->call('setMigrationTab', 'not_started')
        ->callTableAction('beginMigration', $target, data: [
            'member_id' => $target->id,
            'cutoff' => '2024-02-01',
        ])
        ->assertRedirect(MemberResource::editUrlWithRelationManager($target, MigrationStubsRelationManager::class));

    expect(MigrationCycleStub::query()->where('member_id', $target->id)->count())->toBeGreaterThan(0)
        ->and(MigrationCycleStub::query()->where('member_id', $other->id)->count())->toBe(0)
        ->and($target->fresh()->migration_status)->toBe('migration_pending');
});

test('open stubs tab exposes batch classification bulk action', function () {
    $admin = User::create([
        'name' => 'Batch Classify Admin',
        'email' => 'batch-classify-stubs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $member = Member::create([
        'member_number' => 'BATCH-STUB-001',
        'name' => 'Batch Stub Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-02-01'),
    );

    $stubs = MigrationCycleStub::query()->where('member_id', $member->id)->get();

    $table = Livewire::test(MigrationWorkflowPage::class)
        ->call('setMigrationTab', 'stubs')
        ->instance()
        ->getTable();

    expect($table->hasBulkAction('classifySelected'))->toBeTrue();

    Livewire::test(MigrationWorkflowPage::class)
        ->call('setMigrationTab', 'stubs')
        ->callTableBulkAction('classifySelected', $stubs, data: [
            'classification' => MigrationCycleStub::CLASS_WAIVED,
            'notes' => 'Batch on workflow tab',
        ])
        ->assertNotified();

    expect(MigrationCycleStub::query()
        ->where('member_id', $member->id)
        ->where('classification', MigrationCycleStub::CLASS_WAIVED)
        ->count())->toBe($stubs->count());
});

test('member migration cycles relation manager supports batch classification', function () {
    $admin = User::create([
        'name' => 'Member Batch Classify Admin',
        'email' => 'batch-classify-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $member = Member::create([
        'member_number' => 'BATCH-RM-001',
        'name' => 'Relation Manager Batch',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-02-01'),
    );

    $stubs = MigrationCycleStub::query()->where('member_id', $member->id)->get();

    Livewire::test(MigrationStubsRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditMember::class,
    ])
        ->assertSuccessful()
        ->callTableBulkAction('classifySelected', $stubs, data: [
            'classification' => MigrationCycleStub::CLASS_BACKDATED_PAID,
            'notes' => 'Batch on member tab',
        ])
        ->assertNotified();

    expect(MigrationCycleStub::query()
        ->where('member_id', $member->id)
        ->where('classification', MigrationCycleStub::CLASS_BACKDATED_PAID)
        ->count())->toBe($stubs->count());
});
