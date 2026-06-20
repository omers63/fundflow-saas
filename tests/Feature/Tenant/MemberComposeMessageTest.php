<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyMessages\Pages\ListMyMessages;
use App\Filament\Member\Widgets\MemberMessagesTableWidget;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    DirectMessage::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Messaging Member',
        'email' => 'member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-MSG01',
        'name' => 'Messaging Member',
        'email' => 'member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');
});

test('member can compose a message from help inbox widget', function () {
    Livewire::test(MemberMessagesTableWidget::class)
        ->mountTableAction('compose')
        ->setTableActionData([
            'subject' => 'Balance question',
            'body' => 'Please confirm my fund balance.',
        ])
        ->callMountedTableAction()
        ->assertHasNoErrors();

    expect(DirectMessage::query()->count())->toBe(1)
        ->and(DirectMessage::query()->value('subject'))->toBe('Balance question')
        ->and(DirectMessage::query()->value('from_user_id'))->toBe($this->memberUser->id)
        ->and(DirectMessage::query()->value('to_user_id'))->toBe($this->admin->id);
});

test('messages list opens compose modal when compose query flag is present', function () {
    Livewire::withQueryParams(['compose' => '1'])
        ->test(ListMyMessages::class)
        ->assertSet('defaultAction', 'compose');
});
