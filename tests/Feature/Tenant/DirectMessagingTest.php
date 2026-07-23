<?php

use App\Filament\Tenant\Pages\CommunicationsWorkspacePage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\DirectMessagingService;
use App\Services\Tenant\MemberAudienceResolver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $tenant = $this->initializeTenancy();
    $domain = $tenant->domains()->first()?->domain ?? 'testing.localhost';
    $this->tenantBaseUrl = 'http://'.$domain;

    Member::query()->delete();
    User::query()->delete();
    DirectMessage::query()->forceDelete();

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-MSG01',
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->messaging = app(DirectMessagingService::class);
});

test('admin recipient resolves to last admin who messaged the member', function () {
    $otherAdmin = User::create([
        'name' => 'Other Admin',
        'email' => 'other@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    DirectMessage::create([
        'from_user_id' => $this->admin->id,
        'to_user_id' => $this->memberUser->id,
        'subject' => 'Hello',
        'body' => 'First admin',
    ]);

    DirectMessage::create([
        'from_user_id' => $otherAdmin->id,
        'to_user_id' => $this->memberUser->id,
        'subject' => 'Follow up',
        'body' => 'Second admin',
    ]);

    $resolved = $this->messaging->resolveAdminRecipientForMember($this->memberUser->id);

    expect($resolved?->id)->toBe($otherAdmin->id);
});

test('admin can send threaded messages to a member', function () {
    $this->actingAs($this->admin, 'tenant');

    expect($this->messaging->sendAdminToMember($this->member, $this->admin, 'Hello member'))->toBeTrue()
        ->and(DirectMessage::query()->count())->toBe(1);

    expect($this->messaging->sendAdminToMember($this->member, $this->admin, 'Follow up'))->toBeTrue()
        ->and(DirectMessage::query()->count())->toBe(2)
        ->and(DirectMessage::query()->whereNotNull('parent_id')->count())->toBe(1);
});

test('opening admin conversation marks member messages as read', function () {
    $message = DirectMessage::create([
        'from_user_id' => $this->memberUser->id,
        'to_user_id' => $this->admin->id,
        'subject' => 'Question',
        'body' => 'Need help',
    ]);

    expect($message->read_at)->toBeNull();

    $this->actingAs($this->admin, 'tenant');
    $this->messaging->conversationMessagesForAdmin($this->member, $this->admin->id);

    expect($message->fresh()->read_at)->not->toBeNull();
});

test('attachment download requires participant', function () {
    $message = DirectMessage::create([
        'from_user_id' => $this->admin->id,
        'to_user_id' => $this->memberUser->id,
        'subject' => 'File',
        'body' => 'See attached',
        'attachments' => ['direct-messages/test.pdf'],
    ]);

    Storage::disk('public')->put('direct-messages/test.pdf', 'pdf');

    $this->actingAs($this->memberUser, 'tenant')
        ->get($this->tenantBaseUrl.route('tenant.direct-messages.attachment', ['message' => $message->id, 'index' => 0], false))
        ->assertSuccessful();

    $stranger = User::create([
        'name' => 'Stranger',
        'email' => 'stranger@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($stranger, 'tenant')
        ->get($this->tenantBaseUrl.route('tenant.direct-messages.attachment', ['message' => $message->id, 'index' => 0], false))
        ->assertForbidden();
});

test('messages inbox page is admin only', function () {
    expect(MessagesInboxPage::canAccess())->toBeFalse();
    expect(CommunicationsWorkspacePage::canAccess())->toBeFalse();

    $this->actingAs($this->admin, 'tenant');
    expect(MessagesInboxPage::canAccess())->toBeTrue();
    expect(CommunicationsWorkspacePage::canAccess())->toBeTrue();
});

test('legacy messages route redirects into communications inbox tab', function () {
    Filament::setCurrentPanel('tenant');
    $this->actingAs($this->admin, 'tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(MessagesInboxPage::class)
        ->assertRedirect(CommunicationsWorkspacePage::getUrl(
            ['sideTab' => 'inbox'],
            panel: 'tenant',
        ));
});

test('communications inbox exposes conversation header actions only', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(CommunicationsWorkspacePage::class, ['sideTab' => 'inbox'])
        ->assertSuccessful()
        ->assertActionExists('message_all_members')
        ->assertActionExists('support_requests')
        ->assertActionDoesNotExist('compose_announcement')
        ->assertActionDoesNotExist('announcements')
        ->mountAction('message_all_members')
        ->assertActionDataSet([
            'audience' => MemberAudienceResolver::ALL_ACTIVE,
        ]);
});
