<?php

declare(strict_types=1);

use App\Filament\Member\Pages\SupportPage;
use App\Filament\Member\Widgets\MyMemberRequestsTableWidget;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ViewMemberRequest;
use App\Filament\Tenant\Resources\SupportRequests\Pages\ListSupportRequests;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\MemberRequestService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    MemberRequest::query()->delete();
    SupportRequest::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Requests Admin',
        'email' => 'requests-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Requests Member',
        'email' => 'requests-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-REQ-01',
        'name' => 'Requests Member',
        'email' => 'requests-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('tenant admin can list member and support requests', function () {
    SupportRequest::query()->create([
        'user_id' => $this->memberUser->id,
        'member_id' => $this->member->id,
        'category' => SupportRequest::CATEGORY_GENERAL_INQUIRY,
        'subject' => 'Help needed',
        'message' => 'Please review my balance.',
    ]);

    MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_ADD_DEPENDENT,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['details' => 'Add my spouse as dependent.'],
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSupportRequests::class)
        ->assertSuccessful()
        ->assertSee('Help needed');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberRequests::class)
        ->assertSuccessful()
        ->assertSee('Add my spouse as dependent.');

    expect(MemberRequestResource::getNavigationBadge())->toBe('1');
});

test('member can submit support request and household request from support page', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(SupportPage::class)
        ->assertSuccessful()
        ->mountAction('submit_request')
        ->setActionData([
            'category' => SupportRequest::CATEGORY_BALANCE_QUERY,
            'subject' => 'Balance check',
            'message' => 'What is my current cash balance?',
        ])
        ->callMountedAction()
        ->assertNotified();

    expect(SupportRequest::query()->count())->toBe(1);

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->mountTableAction('requestFreezeMembership')
        ->setTableActionData(['reason' => 'Traveling abroad for six months.'])
        ->callMountedTableAction()
        ->assertNotified();

    expect(MemberRequest::query()->count())->toBe(1)
        ->and(MemberRequest::query()->first()->type)->toBe(MemberRequest::TYPE_FREEZE_MEMBERSHIP);
});

test('admin can approve independence request', function () {
    $parentUser = User::create([
        'name' => 'Household Parent',
        'email' => 'parent-req@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-PARENT',
        'name' => 'Household Parent',
        'email' => 'parent-req@fund.test',
        'household_email' => 'parent-req@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $dependent = Member::create([
        'user_id' => $this->memberUser->id,
        'parent_member_id' => $parent->id,
        'member_number' => 'MEM-DEP',
        'name' => 'Requests Member',
        'email' => 'requests-member@fund.test',
        'household_email' => 'parent-req@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($dependent);

    $request = app(MemberRequestService::class)->submit(
        $dependent->fresh(),
        MemberRequest::TYPE_REQUEST_INDEPENDENCE,
        [],
    );

    app(MemberRequestService::class)->approve($request->fresh(), $this->admin);

    expect($dependent->fresh()->parent_member_id)->toBeNull()
        ->and($request->fresh()->status)->toBe(MemberRequest::STATUS_APPROVED);
});

test('member requests list opens view page on row click without row actions column', function () {
    $request = MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_ADD_DEPENDENT,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['details' => 'Add dependent from list test.'],
    ]);

    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberRequests::class)
        ->assertSuccessful()
        ->assertSee('ff-member-requests-insights', false);

    expect($component->instance()->getTable()->getRecordActions())->toBe([])
        ->and($component->instance()->getTable()->getRecordUrl($request))
        ->toBe(MemberRequestResource::getUrl('view', ['record' => $request]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ViewMemberRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('Approve'))
        ->assertSee('Add dependent from list test.');
});
