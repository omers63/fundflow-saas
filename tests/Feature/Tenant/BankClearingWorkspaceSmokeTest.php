<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\User;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->admin = User::create([
        'name' => 'Bank Clearing Admin',
        'email' => 'bank-clearing-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
});

it('renders the bank clearing workspace shell on the default queue tab', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->assertSuccessful()
        ->assertSet('activeTab', BankClearingTabRegistry::TAB_QUEUE)
        ->assertSee(__('Work queue'))
        ->assertSee(__('Bank ledger'))
        ->assertSee(__('Import history'))
        ->assertSee(__('All open'));
});

it('switches bank tabs via livewire actions', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->call('setBankTab', BankClearingTabRegistry::TAB_LEDGER)
        ->assertSet('activeTab', BankClearingTabRegistry::TAB_LEDGER)
        ->call('setBankTab', BankClearingTabRegistry::TAB_HISTORY)
        ->assertSet('activeTab', BankClearingTabRegistry::TAB_HISTORY)
        ->call('toggleClosedHistoryLines')
        ->assertSet('showClosedHistoryLines', true)
        ->assertSet('historySection', BankClearingTabRegistry::HISTORY_CLOSED);
});

it('scopes column manager session keys per bank clearing tab', function () {
    $queueKey = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->instance()
        ->getTableColumnsSessionKey();

    $ledgerKey = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, ['activeTab' => BankClearingTabRegistry::TAB_LEDGER])
        ->instance()
        ->getTableColumnsSessionKey();

    expect($queueKey)->not->toBe($ledgerKey);
});

it('reloads work queue columns after switching away and back', function () {
    $queueColumns = [
        'transaction_date',
        'queue_slice',
        'queue_kind',
        'bankStatement.filename',
        'amount',
        'member.name',
        'description',
        'status',
    ];

    $ledgerColumns = [
        'transacted_at',
        'type',
        'amount',
        'balance_after',
        'description',
        'linked_source',
        'id',
        'member.name',
    ];

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class);

    foreach ($queueColumns as $column) {
        $component->assertTableColumnVisible($column);
    }

    $component->call('setBankTab', BankClearingTabRegistry::TAB_LEDGER);

    foreach ($ledgerColumns as $column) {
        $component->assertTableColumnVisible($column);
    }

    $component->call('setBankTab', BankClearingTabRegistry::TAB_QUEUE);

    foreach ($queueColumns as $column) {
        $component->assertTableColumnVisible($column);
    }
});

it('builds list urls with queue and history parameters', function () {
    $queueUrl = BankAccountsResource::listUrl(
        BankClearingTabRegistry::TAB_QUEUE,
        queueFilter: BankClearingTabRegistry::FILTER_OPERATIONS,
    );

    expect($queueUrl)->not->toContain('tab=')
        ->and($queueUrl)->toContain('queueFilter=operations');

    $historyUrl = BankAccountsResource::listUrl(
        BankClearingTabRegistry::TAB_HISTORY,
        historySection: BankClearingTabRegistry::HISTORY_CLOSED,
    );

    expect($historyUrl)->toContain('tab=history')
        ->and($historyUrl)->toContain('historySection=closed');
});

it('uses a single-column header widget grid on the ledger tab', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->call('setBankTab', BankClearingTabRegistry::TAB_LEDGER)
        ->assertSet('activeTab', BankClearingTabRegistry::TAB_LEDGER);

    expect($component->instance()->getHeaderWidgetsColumns())->toBe(1);
});

it('shows a slim ledger insights strip without the full dashboard', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['tab' => 'ledger'])
        ->test(ListBankAccounts::class, [
            'activeTab' => BankClearingTabRegistry::TAB_LEDGER,
        ])
        ->assertSuccessful()
        ->assertSee(__('Master bank'))
        ->assertSee(__('Master cash'))
        ->assertSee(__('Open queue'))
        ->assertDontSee(__('Show balances & trends'))
        ->assertDontSee(__('Posting pipeline'))
        ->assertDontSee(__('Recent statements'))
        ->assertDontSee(__('Pending inbound'))
        ->assertDontSee(__('Import volume'));
});

it('shows a slim import history insights strip without duplicate statement lists', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['tab' => 'history'])
        ->test(ListBankAccounts::class, [
            'activeTab' => BankClearingTabRegistry::TAB_HISTORY,
        ])
        ->assertSuccessful()
        ->assertSee(__('Imports'))
        ->assertSee(__('Failed'))
        ->assertSee(__('Duplicates'))
        ->assertSee(__('Processing'))
        ->assertSee(__('Import batches'))
        ->assertDontSee(__('Show balances & trends'))
        ->assertDontSee(__('Posting pipeline'))
        ->assertDontSee(__('Recent statements'))
        ->assertDontSee(__('Pending inbound'))
        ->assertDontSee(__('Templates'));
});

it('shows the balances toggle on the work queue and keeps header widgets empty', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'activeTab' => BankClearingTabRegistry::TAB_QUEUE,
        ])
        ->assertSuccessful()
        ->assertSee(__('Show balances & trends'))
        ->assertDontSee(__('Posting pipeline'));

    expect($component->instance()->getHeaderWidgetsColumns())->toBe(1);
});

it('can expand work queue balances without crashing insights-head', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'activeTab' => BankClearingTabRegistry::TAB_QUEUE,
        ])
        ->assertSuccessful()
        ->call('toggleQueueBalances')
        ->assertSet('showQueueBalances', true)
        ->assertSuccessful()
        ->assertSee(__('Posting pipeline'))
        ->assertSee(__('Hide balances & trends'));
});

it('exposes the bank import action in workspace panel actions on the queue tab', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class);

    $actionNames = collect($component->instance()->getCachedWorkspacePanelActions())
        ->flatMap(function ($action) {
            if ($action instanceof ActionGroup) {
                return collect($action->getFlatActions())->map->getName();
            }

            return [$action->getName()];
        })
        ->all();

    expect($actionNames)->toContain('import');
});
