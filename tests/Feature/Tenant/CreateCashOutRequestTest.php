<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\CreateCashOutRequest;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\ListCashOutRequests;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
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

    Account::query()->delete();
    Member::query()->delete();
    CashOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-create-cashout@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'MEM-CO99',
        'name' => 'Cash Out Target',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);
    $this->member->refresh();

    AccountingService::withoutMemberCashCollection(function () use ($accounting): void {
        $accounting->credit(
            $this->member->cashAccount,
            5000,
            'Seed cash for admin cash-out test',
        );
    });

    $this->member->refresh();
});

test('cash outs list shows new cash out header action', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListCashOutRequests::class)
        ->assertSuccessful()
        ->assertSee(__('New cash out'))
        ->assertTableColumnExists('id');
});

test('admin can create and auto-approve a cash out for any member', function () {
    Notification::fake();

    Livewire::actingAs($this->admin, 'tenant')
        ->test(CreateCashOutRequest::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'amount' => 1500,
            'notes' => 'Admin-initiated cash out',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect(CashOutRequestResource::getUrl('index'));

    $request = CashOutRequest::query()->where('member_id', $this->member->id)->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('accepted')
        ->and($request->reviewed_by)->toBe($this->admin->id)
        ->and($request->reviewed_at)->not->toBeNull()
        ->and($request->amount)->toBe('1500.00')
        ->and($request->notes)->toBe('Admin-initiated cash out')
        ->and($request->admin_remarks)->toBe('Admin-initiated cash out')
        ->and($request->bank_transaction_id)->not->toBeNull()
        ->and((float) $this->member->cashAccount->fresh()->balance)->toBe(3500.0);
});

test('create cash out page prefills member from query string', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['member_id' => (string) $this->member->id])
        ->test(CreateCashOutRequest::class)
        ->assertFormSet([
            'member_id' => $this->member->id,
        ]);
});
