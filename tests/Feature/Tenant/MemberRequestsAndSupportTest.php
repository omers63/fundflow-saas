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
use App\Notifications\Tenant\NewMemberRequestNotification;
use App\Notifications\Tenant\NewSupportRequestNotification;
use App\Services\AccountingService;
use App\Services\Tenant\MemberRequestService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

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

    $requestsPage = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberRequests::class)
        ->assertSuccessful()
        ->assertSee('Add my spouse as dependent.');

    $requestHeaderNames = collect($requestsPage->instance()->getCachedHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    $tableHeaderActionNames = collect($requestsPage->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($requestHeaderNames)->not->toContain('backToMembers')
        ->and($tableHeaderActionNames)->toContain('newRequest')
        ->and(MemberRequestResource::getNavigationBadge())->toBe('1');
});

test('member can submit support request and household request from support page', function () {
    Notification::fake();

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

    Notification::assertSentTo(
        $this->admin,
        NewSupportRequestNotification::class,
        fn (NewSupportRequestNotification $notification, array $channels): bool => in_array('database', $channels, true)
        && in_array(WebPushChannel::class, $channels, true),
    );

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->mountTableAction('requestFreezeMembership')
        ->setTableActionData(['reason' => 'Traveling abroad for six months.'])
        ->callMountedTableAction()
        ->assertNotified();

    expect(MemberRequest::query()->count())->toBe(1)
        ->and(MemberRequest::query()->first()->type)->toBe(MemberRequest::TYPE_FREEZE_MEMBERSHIP);

    Notification::assertSentTo(
        $this->admin,
        NewMemberRequestNotification::class,
        fn (NewMemberRequestNotification $notification, array $channels): bool => in_array('database', $channels, true)
        && in_array(WebPushChannel::class, $channels, true),
    );
});

test('membership requests table description reflects household link', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->assertSuccessful()
        ->assertSee(__('Freeze or leave the fund while you have portal access. Unfreeze, reinstate, and payout-release requests can be submitted from the sign-in page when portal access is blocked.'), false);

    $parentUser = User::create([
        'name' => 'Household Parent',
        'email' => 'parent-desc@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-PARENT-DESC',
        'name' => 'Household Parent',
        'email' => 'parent-desc@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->member->update(['parent_member_id' => $parent->id]);
    $this->memberUser->unsetRelation('members');
    $this->memberUser->refresh();

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->assertSuccessful()
        ->assertSee(__('Freeze, leave the fund, or request independence from your household parent. Unfreeze, reinstate, and payout-release requests can also be submitted from the sign-in page when portal access is blocked.'), false)
        ->assertDontSee(__('My dependents page'), false);
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

test('view member request reject action refreshes the record without error', function () {
    $request = MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['amount' => 5000],
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ViewMemberRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->callAction('reject', ['admin_note' => 'Not this cycle.'])
        ->assertNotified();

    expect($request->fresh()->status)->toBe(MemberRequest::STATUS_REJECTED)
        ->and($request->fresh()->admin_note)->toBe('Not this cycle.');
});
