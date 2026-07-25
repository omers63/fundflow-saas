<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages\CreateMemberCashTransferRequest;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    MemberCashTransferRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-create-xfer@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->from = Member::create([
        'member_number' => 'MEM-XF-FROM',
        'name' => 'From Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'opening_cash_balance' => 5000,
    ]);

    $this->to = Member::create([
        'member_number' => 'MEM-XF-TO',
        'name' => 'To Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->from);
    $accounting->createMemberAccounts($this->to);
    $this->from->cashAccount->update(['balance' => 5000]);
    Account::masterCash()->update(['balance' => 105000]);
});

test('admin create cash transfer page renders without error', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(CreateMemberCashTransferRequest::class)
        ->assertSuccessful()
        ->assertSee(__('Cash transfer'))
        ->assertSee(__('Available to transfer'));
});

test('admin can create an immediate cash transfer between members', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(CreateMemberCashTransferRequest::class)
        ->fillForm([
            'from_member_id' => $this->from->id,
            'to_member_id' => $this->to->id,
            'amount' => 1500,
            'notes' => 'Admin peer transfer',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect(MemberCashTransferRequestResource::getUrl('index'));

    $request = MemberCashTransferRequest::query()->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('accepted')
        ->and((float) $request->amount)->toBe(1500.0)
        ->and((float) $this->from->cashAccount->fresh()->balance)->toBe(3500.0)
        ->and((float) $this->to->cashAccount->fresh()->balance)->toBe(1500.0);
});
