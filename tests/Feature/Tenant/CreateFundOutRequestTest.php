<?php

declare(strict_types=1);

use App\Filament\Support\MemberFilamentActions;
use App\Filament\Tenant\Resources\FundOutRequests\Pages\ListFundOutRequests;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
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
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    Member::query()->delete();
    FundOutRequest::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin-create-fundout@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'MEM-FO99',
        'name' => 'Fund Out Target',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);
    $this->member->refresh();

    $accounting->creditMemberFundWithMasterMirror(
        $this->member->fundAccount,
        4000,
        'Seed fund for admin fund-out test',
        __('(master fund mirror)'),
        $this->member,
    );

    $this->member->refresh();
});

test('fund outs list shows new fund out as a table header modal action', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListFundOutRequests::class)
        ->assertSuccessful()
        ->assertSee(__('New fund out'))
        ->assertTableColumnExists('id')
        ->assertTableActionExists('create');

    $pageHeaderNames = collect($component->instance()->getCachedHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    $tableHeaderActionNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($pageHeaderNames)->not->toContain('create')
        ->and($tableHeaderActionNames)->toContain('create');

    $createAction = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn ($action) => $action->getName() === 'create');

    expect($createAction)->not->toBeNull()
        ->and($createAction->getUrl())->toBeNull()
        ->and($createAction->getModalHeading())->toBe(__('New fund out'));
});

test('admin can create and auto-approve a fund out for any member via modal with date', function () {
    Notification::fake();

    $fundOutDate = BusinessDay::today()->subDays(2);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListFundOutRequests::class)
        ->callTableAction('create', data: [
            'member_id' => $this->member->id,
            'amount' => 1500,
            'fund_out_date' => $fundOutDate->toDateString(),
            'notes' => 'Admin-initiated fund out',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $request = FundOutRequest::query()->where('member_id', $this->member->id)->first();
    $expectedAt = MemberFilamentActions::resolveCashOutDate($fundOutDate->toDateString());

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('accepted')
        ->and($request->reviewed_by)->toBe($this->admin->id)
        ->and($request->reviewed_at?->toDateTimeString())->toBe($expectedAt->toDateTimeString())
        ->and($request->amount)->toBe('1500.00')
        ->and($request->notes)->toBe('Admin-initiated fund out')
        ->and($request->admin_remarks)->toBe('Admin-initiated fund out')
        ->and((float) $this->member->fundAccount->fresh()->balance)->toBe(2500.0)
        ->and((float) $this->member->cashAccount->fresh()->balance)->toBe(1500.0);

    $ledgerTxn = Transaction::query()
        ->where('reference_type', $request->getMorphClass())
        ->where('reference_id', $request->id)
        ->first();

    expect($ledgerTxn)->not->toBeNull()
        ->and($ledgerTxn->transacted_at?->toDateTimeString())->toBe($expectedAt->toDateTimeString());
});
