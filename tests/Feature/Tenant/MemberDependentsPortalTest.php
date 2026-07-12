<?php

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Resources\MyDependents\Pages\ListMyDependents;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberDependentsInsightsService;
use App\Services\Tenant\HouseholdMemberService;
use App\Services\Tenant\ImpersonationService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $tenant = $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    $domain = 'testing.localhost';
    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }
    $this->tenantBaseUrl = 'http://'.$domain;

    Member::query()->delete();
    User::query()->delete();

    $this->parentUser = User::create([
        'name' => 'Parent User',
        'email' => 'parent@dependents.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->parent = Member::create([
        'user_id' => $this->parentUser->id,
        'member_number' => 'MEM-P001',
        'name' => 'Parent User',
        'email' => 'parent@dependents.test',
        'household_email' => 'parent@dependents.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->parent);

    $this->dependentUser = User::create([
        'name' => 'Child Member',
        'email' => 'child@household.members.local',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->dependent = Member::create([
        'user_id' => $this->dependentUser->id,
        'parent_member_id' => $this->parent->id,
        'member_number' => 'MEM-C001',
        'name' => 'Child Member',
        'email' => 'parent@dependents.test',
        'household_email' => 'parent@dependents.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->dependent);
});

test('parent with dependents can access my dependents resource', function () {
    $this->actingAs($this->parentUser, 'tenant');

    expect(MyDependentResource::canAccess())->toBeTrue()
        ->and(MyDependentResource::shouldRegisterNavigation())->toBeTrue();

    $records = MyDependentResource::getEloquentQuery()->get();

    expect($records)->toHaveCount(1)
        ->and($records->first()->id)->toBe($this->dependent->id);
});

test('dependent member can access dependents resource and navigation', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    expect(MyDependentResource::canAccess())->toBeTrue()
        ->and(MyDependentResource::shouldRegisterNavigation())->toBeTrue();

    $records = MyDependentResource::getEloquentQuery()->get();

    expect($records)->toHaveCount(0);
});

test('parent without dependents can access dependents resource and navigation', function () {
    $this->dependent->delete();
    $this->dependentUser->delete();

    $this->actingAs($this->parentUser, 'tenant');

    expect(MyDependentResource::canAccess())->toBeTrue()
        ->and(MyDependentResource::shouldRegisterNavigation())->toBeTrue();

    expect(MyDependentResource::getEloquentQuery()->get())->toHaveCount(0);
});

test('parent can list dependents page', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertSuccessful()
        ->assertSee('Child Member')
        ->assertSee('MEM-C001')
        ->assertSee(__('Set contributions'))
        ->assertSee(__('Cash to transfer'))
        ->assertTableActionDoesNotExist('view')
        ->assertTableActionExists('openDependentPortal');

    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('dependents table exposes household request header actions', function () {
    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertTableActionExists('requestAddDependent')
        ->assertTableActionExists('requestRemoveDependent')
        ->assertTableActionExists('applyForDependent');
});

test('dependent member sees empty dependents table with add action', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertSuccessful()
        ->assertTableActionExists('requestAddDependent')
        ->assertTableActionHidden('requestRemoveDependent')
        ->assertTableActionHidden('applyForDependent')
        ->assertSee(__('No dependents'));
});

test('sponsored dependent can request add dependent with new email and leave household', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->callTableAction('requestAddDependent', data: [
            'new_email' => 'newparent@dependents.test',
            'details' => 'Please add my child Jane Doe.',
        ])
        ->assertHasNoErrors();

    $this->dependent->refresh();
    $this->dependentUser->refresh();

    expect($this->dependent->parent_member_id)->toBeNull()
        ->and($this->dependent->email)->toBe('newparent@dependents.test')
        ->and($this->dependentUser->email)->toBe('newparent@dependents.test');

    $request = MemberRequest::query()
        ->where('requester_member_id', $this->dependent->id)
        ->where('type', MemberRequest::TYPE_ADD_DEPENDENT)
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->payload['details'])->toBe('Please add my child Jane Doe.')
        ->and($request->payload['new_email'])->toBe('newparent@dependents.test');
});

test('dependent table row links to impersonation route', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $component = Livewire::test(ListMyDependents::class);
    $recordUrl = $component->instance()->getTable()->getRecordUrl($this->dependent);

    expect($recordUrl)->toBe(
        route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent]),
    );
});

test('dependents insights snapshot summarizes household', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $snapshot = app(MemberDependentsInsightsService::class)->snapshot($this->parent);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_period', 'dependents_count'])
        ->and($snapshot['dependents_count'])->toBe(1)
        ->and($snapshot['kpis'])->toHaveCount(4);
});

test('manage funding refreshes dependents insights on the list page', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->parent->update([
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
    ]);
    $this->dependent->update([
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
    ]);

    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertSet('dependentsInsightsVersion', 0)
        ->callTableAction('setDependentAllocation', $this->dependent, data: [
            'funded_by_parent' => true,
            'monthly_contribution_amount' => 1000,
        ])
        ->assertHasNoErrors()
        ->assertSet('dependentsInsightsVersion', 1)
        ->assertSee(__('Set contributions'));

    expect((int) $this->dependent->fresh()->monthly_contribution_amount)->toBe(1000);

    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('dependents insights refresh bumps version on list page', function () {
    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertSet('dependentsInsightsVersion', 0)
        ->call('refreshDependentsInsights')
        ->assertSet('dependentsInsightsVersion', 1);
});

test('parent can switch into dependent portal', function () {
    $this->actingAs($this->parentUser, 'tenant');

    app(ImpersonationService::class)->start($this->parentUser, $this->dependentUser, $this->dependent);

    expect(auth('tenant')->id())->toBe($this->dependentUser->id)
        ->and(session('impersonator_user_id'))->toBe($this->parentUser->id)
        ->and($this->dependentUser->fresh()->activeMember()?->id)->toBe($this->dependent->id);
});

test('impersonation route switches to dependent user', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $response = $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false));

    $response->assertRedirect('/member');
    $this->assertAuthenticatedAs($this->dependentUser, 'tenant');
    expect(session('impersonator_user_id'))->toBe($this->parentUser->id)
        ->and(session('impersonated_member_id'))->toBe($this->dependent->id)
        ->and($this->dependentUser->fresh()->activeMember()?->id)->toBe($this->dependent->id);
});

test('impersonation route allows parent when active_member_id is set', function () {
    $this->actingAs($this->parentUser, 'tenant');
    session(['active_member_id' => $this->parent->id]);

    $response = $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false));

    $response->assertRedirect('/member');
    $this->assertAuthenticatedAs($this->dependentUser, 'tenant');
});

test('impersonated user can open member panel after switch', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false))
        ->assertRedirect('/member');

    $this->get($this->tenantBaseUrl.'/member')->assertOk();
    $this->assertAuthenticatedAs($this->dependentUser, 'tenant');
});

test('impersonated user can open member panel after parent browsed member area', function () {
    $this->actingAs($this->parentUser, 'tenant');

    $this->get($this->tenantBaseUrl.'/member')->assertOk();

    $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false))
        ->assertRedirect('/member');

    $this->get($this->tenantBaseUrl.'/member')->assertOk();
    $this->assertAuthenticatedAs($this->dependentUser, 'tenant');
    expect(session('impersonator_user_id'))->toBe($this->parentUser->id);
});

test('stop impersonation route restores parent portal', function () {
    $this->actingAs($this->parentUser, 'tenant');

    app(ImpersonationService::class)->start(
        $this->parentUser,
        $this->dependentUser,
        $this->dependent,
    );

    $response = $this->post(
        $this->tenantBaseUrl.route('tenant.member.impersonation.stop', [], false),
        ['_token' => 'test'],
    );

    $response->assertRedirect('/member');
    $this->assertAuthenticatedAs($this->parentUser, 'tenant');
    expect(session('impersonator_user_id'))->toBeNull();
});

test('parent cannot impersonate a detached dependent', function () {
    app(HouseholdMemberService::class)->removeFromHousehold($this->dependent);

    $this->actingAs($this->parentUser, 'tenant');

    $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false))
        ->assertForbidden();
});

test('impersonation route returns forbidden for non parent', function () {
    $this->actingAs($this->dependentUser, 'tenant');

    $this->get($this->tenantBaseUrl.route('tenant.member.dependents.impersonate', ['dependent' => $this->dependent], false))
        ->assertForbidden();
});

test('dependents table hides exempt contribution label for loan cycle dependent', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    LoanInstallment::query()->delete();
    Loan::query()->delete();

    $loan = Loan::create([
        'member_id' => $this->dependent->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    foreach ([
        ['month' => 6, 'year' => 2026, 'number' => 1],
        ['month' => 7, 'year' => 2026, 'number' => 2],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    $this->actingAs($this->parentUser, 'tenant');

    Livewire::test(ListMyDependents::class)
        ->assertSuccessful()
        ->assertSee(__('EMI: :status', ['status' => __('Pending')]))
        ->assertDontSee(__('Contribution: :status', ['status' => __('Exempt')]));

    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});
