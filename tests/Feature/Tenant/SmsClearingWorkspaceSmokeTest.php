<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\SmsClearing\Pages\ListSmsClearing;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use App\Models\Tenant\User;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->admin = User::create([
        'name' => 'SMS Clearing Admin',
        'email' => 'sms-clearing-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
});

it('renders the sms clearing workspace shell on the default queue tab', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class)
        ->assertSuccessful()
        ->assertSet('activeTab', SmsClearingTabRegistry::TAB_QUEUE)
        ->assertSee(__('Work queue'))
        ->assertSee(__('Posted ledger'))
        ->assertSee(__('Import history'))
        ->assertSee(__('All open'));
});

it('switches sms tabs via livewire actions', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class)
        ->call('setSmsTab', SmsClearingTabRegistry::TAB_LEDGER)
        ->assertSet('activeTab', SmsClearingTabRegistry::TAB_LEDGER)
        ->call('setSmsTab', SmsClearingTabRegistry::TAB_HISTORY)
        ->assertSet('activeTab', SmsClearingTabRegistry::TAB_HISTORY)
        ->call('toggleDuplicateHistory')
        ->assertSet('showDuplicateHistory', true)
        ->assertSet('historySection', SmsClearingTabRegistry::HISTORY_DUPLICATES);
});

it('builds list urls with queue and history parameters', function () {
    $queueUrl = SmsClearingResource::listUrl(
        SmsClearingTabRegistry::TAB_QUEUE,
        queueFilter: SmsClearingTabRegistry::FILTER_UNMATCHED,
    );

    expect($queueUrl)->not->toContain('tab=')
        ->and($queueUrl)->toContain('queueFilter=unmatched');

    $historyUrl = SmsClearingResource::listUrl(
        SmsClearingTabRegistry::TAB_HISTORY,
        historySection: SmsClearingTabRegistry::HISTORY_DUPLICATES,
    );

    expect($historyUrl)->toContain('tab=history')
        ->and($historyUrl)->toContain('historySection=duplicates');
});

it('exposes the sms import action in workspace panel actions on the queue tab', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class);

    $actionNames = collect($component->instance()->getCachedWorkspacePanelActions())
        ->flatMap(function ($action) {
            if ($action instanceof ActionGroup) {
                return collect($action->getFlatActions())->map->getName();
            }

            return [$action->getName()];
        })
        ->all();

    expect($actionNames)->toContain('importSms');
});

it('uses a single-column header widget grid when applicable', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class);

    expect($component->instance()->getHeaderWidgetsColumns())->toBe(1);
});
