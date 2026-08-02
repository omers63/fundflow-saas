<?php

declare(strict_types=1);

use App\Filament\Member\Pages\CashAccountPage;
use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Filament\Member\Resources\MyCashOutRequests\Pages\ListMyCashOutRequests;
use App\Filament\Member\Resources\MyFundOutRequests\MyFundOutRequestResource;
use App\Filament\Member\Resources\MyFundOutRequests\Pages\ListMyFundOutRequests;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundOutRequest;
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
    Filament::setCurrentPanel('member');
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    CashOutRequest::query()->delete();
    FundOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Withdrawal Member',
        'email' => 'withdrawal-member@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-WD1',
        'name' => 'Withdrawal Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);

    AccountingService::withoutMemberCashCollection(function () use ($accounting): void {
        $accounting->credit($this->member->cashAccount, 3000, 'Seed cash');
    });

    $accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        4000,
        'Seed fund',
        __('(master fund mirror)'),
        $this->member,
    );

    $this->member->refresh();
});

test('member cash outs list exposes only the cash out header action', function () {
    $component = Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyCashOutRequests::class)
        ->assertSuccessful()
        ->assertActionExists('requestCashOut')
        ->assertActionDoesNotExist('requestFundOut');

    $names = collect($component->instance()->getCachedHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toContain('requestCashOut')
        ->and($names)->not->toContain('requestFundOut');
});

test('member fund outs list exposes only the fund out header action', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyFundOutRequests::class)
        ->assertSuccessful()
        ->assertActionExists('requestFundOut')
        ->assertActionDoesNotExist('requestCashOut');
});

test('cash account page exposes cash out and fund out modals', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(CashAccountPage::class)
        ->assertSuccessful()
        ->assertActionExists('requestCashOut')
        ->assertActionExists('requestFundOut');
});

test('member cash out create page route is removed', function () {
    expect(array_keys(MyCashOutRequestResource::getPages()))->toBe(['index'])
        ->and(array_keys(MyFundOutRequestResource::getPages()))->toBe(['index']);
});

test('member can submit cash out and fund out from their respective list modals', function () {
    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyCashOutRequests::class)
        ->callAction('requestCashOut', [
            'amount' => 100,
            'notes' => 'Cash modal',
        ])
        ->assertHasNoActionErrors();

    expect(CashOutRequest::query()->where('member_id', $this->member->id)->count())->toBe(1);

    Livewire::actingAs($this->memberUser, 'tenant')
        ->test(ListMyFundOutRequests::class)
        ->callAction('requestFundOut', [
            'amount' => 250,
            'notes' => 'Fund modal',
        ])
        ->assertHasNoActionErrors();

    expect(FundOutRequest::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and((float) FundOutRequest::query()->first()->amount)->toBe(250.0);
});
