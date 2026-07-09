<?php

declare(strict_types=1);

use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Tenant\Resources\Accounts\Pages\ListAccounts;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberAccountExportService;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->admin = User::create([
        'name' => 'Member Accounts Admin',
        'email' => 'member-accounts@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');

    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
});

function headerActionNames(ListAccounts $page): array
{
    return collect($page->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();
}

test('member accounts all tab exposes import export and create header actions', function () {
    $component = Livewire::test(ListAccounts::class)
        ->assertSet('activeTab', 'all');

    expect(headerActionNames($component->instance()))
        ->toContain('importMembers', 'exportAccounts', 'create');
});

test('member accounts cash tab exposes account import export header actions', function () {
    $component = Livewire::test(ListAccounts::class)
        ->set('activeTab', 'cash');

    expect(headerActionNames($component->instance()))
        ->toContain('importMembers', 'exportAccounts', 'create');
});

test('member accounts fund tab exposes account import export header actions', function () {
    $component = Livewire::test(ListAccounts::class)
        ->set('activeTab', 'fund');

    expect(headerActionNames($component->instance()))
        ->toContain('importMembers', 'exportAccounts', 'create');
});

test('member accounts loans tab exposes loan import export actions with create loan action', function () {
    $component = Livewire::test(ListAccounts::class)
        ->set('activeTab', 'loans');

    $actions = $component->instance()->getTable()->getHeaderActions();
    $names = LoanListTableHeaderActions::flattenActionNames($actions);

    expect($names)
        ->toContain('importLoans', 'exportLoans', 'importRepayments', 'exportRepayments', 'create')
        ->and($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(ActionGroup::class);

    $create = collect(LoanListTableHeaderActions::flattenActionNames($actions))
        ->contains('create');

    expect($create)->toBeTrue();
});

test('member account export includes roster columns and respects account type filter', function () {
    $member = Member::create([
        'member_number' => 'MA-EXPORT-1',
        'name' => 'Export Member',
        'email' => 'export.member.accounts@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);
    $member->cashAccount()->update(['balance' => 750]);
    $member->fundAccount()->update(['balance' => 3200]);

    ob_start();
    app(MemberAccountExportService::class)->downloadCsv('cash')->sendContent();
    $cashCsv = ob_get_clean();

    expect($cashCsv)
        ->toContain('member_number')
        ->toContain('MA-EXPORT-1')
        ->toContain('cash')
        ->toContain('750')
        ->not->toContain('3200');

    ob_start();
    app(MemberAccountExportService::class)->downloadCsv()->sendContent();
    $allCsv = ob_get_clean();

    expect($allCsv)
        ->toContain('cash')
        ->toContain('fund')
        ->toContain('3200');
});
