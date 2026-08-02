<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyCashTransfers\MyCashTransferResource;
use App\Filament\Member\Resources\MyCashTransfers\Pages\ListMyCashTransfers;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyFundPostings\Pages\ListMyFundPostings;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    FundPosting::query()->delete();
    MemberCashTransferRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Modal Member',
        'email' => 'modal-deposit-xfer@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->recipientUser = User::create([
        'name' => 'Modal Recipient',
        'email' => 'modal-recipient@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-MODAL-1',
        'name' => 'Modal Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->recipient = Member::create([
        'user_id' => $this->recipientUser->id,
        'member_number' => 'MEM-MODAL-2',
        'name' => 'Modal Recipient',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);
    $accounting->createMemberAccounts($this->recipient);

    AccountingService::withoutMemberCashCollection(function () use ($accounting): void {
        $accounting->credit($this->member->fresh()->cashAccount, 3000, 'Seed cash');
    });

    $this->member->refresh();
});

test('deposit and cash transfer create routes are removed', function () {
    expect(array_keys(MyFundPostingResource::getPages()))->toBe(['index'])
        ->and(array_keys(MyCashTransferResource::getPages()))->toBe(['index']);
});

test('deposits list exposes new deposit modal header action', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyFundPostings::class)
        ->assertSuccessful()
        ->assertActionExists('requestDeposit');
});

test('cash transfers list exposes request transfer modal header action', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyCashTransfers::class)
        ->assertSuccessful()
        ->assertActionExists('requestCashTransfer');
});

test('member can submit a deposit from the list modal', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyFundPostings::class)
        ->callAction('requestDeposit', [
            'posting_date' => BusinessDay::today()->toDateString(),
            'amount' => 150,
            'reference' => 'MODAL-DEP-1',
            'comments' => 'From modal',
        ])
        ->assertHasNoActionErrors();

    expect(FundPosting::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and((float) FundPosting::query()->first()->amount)->toBe(150.0);
});

test('member can submit a cash transfer from the list modal', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyCashTransfers::class)
        ->callAction('requestCashTransfer', [
            'transfer_mode' => 'other',
            'recipient_name' => 'Modal Recipient',
            'amount' => 200,
            'notes' => 'From modal',
        ])
        ->assertHasNoActionErrors();

    expect(MemberCashTransferRequest::query()->where('from_member_id', $this->member->id)->count())->toBe(1)
        ->and((float) MemberCashTransferRequest::query()->first()->amount)->toBe(200.0)
        ->and(MemberCashTransferRequest::query()->first()->status)->toBe('pending');
});
