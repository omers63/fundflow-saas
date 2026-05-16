<?php

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\AccountsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\DependentsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\LoansRelationManager;
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
        ->assertSee(__('Membership'))
        ->assertSee('500')
        ->assertDontSee(__('Member Information'))
        ->assertDontSee(__('Membership Details'))
        ->assertSee(__('Loans'))
        ->assertSee(__('Messages'));
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

test('member resource relation tabs are ordered with loans before dependents and messages after', function () {
    $relations = MemberResource::getRelations();

    expect($relations)->toBe([
        AccountsRelationManager::class,
        ContributionsRelationManager::class,
        RepaymentsRelationManager::class,
        LoansRelationManager::class,
        DependentsRelationManager::class,
        MessagesRelationManager::class,
    ]);
});
