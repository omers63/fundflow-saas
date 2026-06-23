<?php

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\AccountsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\DependentsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\GuarantorExposureRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\LoansRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MemberTransactionsTabsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MessagesRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\RepaymentsRelationManager;
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

test('edit member page uses member title and combined full-width section', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-edit-member@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Jane Member',
            'email' => 'jane@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-0042',
        'name' => 'Jane Member',
        'email' => 'jane@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('Member'))
        ->assertSee('Jane Member')
        ->assertSee('MEM-0042')
        ->assertSee(__('Membership'))
        ->assertSee('500')
        ->assertSee('ff-member-detail-shell', false)
        ->assertSee('ff-member-stepper', false)
        ->assertDontSee('ff-app-insights-kpi-strip', false)
        ->assertDontSee(__('Member Information'))
        ->assertDontSee(__('Membership Details'))
        ->assertSee(__('Loans'))
        ->assertSee(__('Messages'))
        ->assertDontSee(__('Delinquency'), false);
});

test('member accounts relation manager rows link to account view page', function () {
    $admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-accounts-url@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'user_id' => User::create([
            'name' => 'Account Link Member',
            'email' => 'account-link@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-0099',
        'name' => 'Account Link Member',
        'email' => 'account-link@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $cashAccount = $member->cashAccount;

    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(AccountsRelationManager::class, [
            'ownerRecord' => $member,
            'pageClass' => EditMember::class,
        ])
        ->assertSuccessful()
        ->assertTableColumnExists('name');

    expect($component->instance()->getTable()->getRecordUrl($cashAccount))
        ->toBe(AccountResource::getUrl('view', ['record' => $cashAccount]));
});

test('household relation manager exposes dependent row and bulk actions', function () {
    $admin = User::create([
        'name' => 'Household Admin',
        'email' => 'household-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $parent = Member::create([
        'member_number' => 'MEM-HH-PARENT',
        'name' => 'Household Parent',
        'email' => 'household-parent@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $candidate = Member::create([
        'user_id' => User::create([
            'name' => 'Link Candidate',
            'email' => 'link-candidate@fund.test',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-HH-CAND',
        'name' => 'Link Candidate',
        'email' => 'link-candidate@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $dependent = Member::create([
        'parent_member_id' => $parent->id,
        'member_number' => 'MEM-HH-DEP',
        'name' => 'Household Dependent',
        'email' => 'household-parent@fund.test',
        'household_email' => 'household-parent@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(DependentsRelationManager::class, [
            'ownerRecord' => $parent,
            'pageClass' => EditMember::class,
        ])
        ->assertSuccessful();

    $headerNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerNames)->toContain('addDependent', 'allocateDependents');

    $component->callTableAction('addDependent', data: [
        'member_id' => $candidate->id,
    ])->assertNotified();

    expect($candidate->fresh()->parent_member_id)->toBe($parent->id);

    $component
        ->assertTableActionVisible('view', $dependent)
        ->assertTableActionVisible('edit', $dependent)
        ->assertTableActionVisible('setDependentAllocation', $dependent)
        ->assertTableActionVisible('fundDependentCash', $dependent)
        ->assertTableActionVisible('dependentAllocationHistory', $dependent)
        ->assertTableActionVisible('contributeMember', $dependent);

    $bulkNames = collect($component->instance()->getTable()->getFlatBulkActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($bulkNames)->toContain(
        'bulkUpdateDependentAllocations',
        'contributeSelectedMembers',
        'delete',
    );

    expect($component->instance()->getTable()->getRecordUrl($dependent))
        ->toBe(MemberResource::getUrl('edit', ['record' => $dependent]));
});

test('member resource relation tabs are ordered with loans before dependents and messages after', function () {
    $relations = MemberResource::getRelations();

    expect($relations)->toBe([
        AccountsRelationManager::class,
        LoansRelationManager::class,
        GuarantorExposureRelationManager::class,
        ContributionsRelationManager::class,
        MemberTransactionsTabsRelationManager::class,
        RepaymentsRelationManager::class,
        DependentsRelationManager::class,
        MessagesRelationManager::class,
    ]);
});
