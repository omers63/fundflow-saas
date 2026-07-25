<?php

declare(strict_types=1);

use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Models\Tenant\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->admin = User::create([
        'name' => 'Header Actions Admin',
        'email' => 'header-actions-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

/**
 * @return list<string>
 */
function tableHeaderActionNames(object $component): array
{
    return LoanListTableHeaderActions::flattenActionNames(
        $component->instance()->getTable()->getHeaderActions(),
    );
}

test('requests table exposes new request header action', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberRequests::class)
        ->assertSuccessful();

    $newRequest = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn ($action): bool => $action instanceof Action && $action->getName() === 'newRequest');

    expect(tableHeaderActionNames($component))->toContain('newRequest')
        ->and($newRequest)->not->toBeNull()
        ->and($newRequest->isIconButton())->toBeTrue()
        ->and($newRequest->getTooltip())->toBe(__('New request'));
});

test('loans portfolio table exposes standalone new loan header action', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListLoans::class)
        ->set('activeTab', 'portfolio')
        ->assertSuccessful();

    $headerActions = $component->instance()->getTable()->getHeaderActions();
    $create = collect($headerActions)->first(
        fn ($action): bool => $action instanceof Action && $action->getName() === 'create',
    );

    expect(tableHeaderActionNames($component))->toContain('create')
        ->and($create)->not->toBeNull()
        ->and($create->isIconButton())->toBeTrue()
        ->and($create->getTooltip())->toBe(__('New loan'))
        ->and(collect($headerActions)->contains(fn ($action): bool => $action instanceof ActionGroup))->toBeTrue();
});

test('contributions ledger table exposes new contribution header action', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertSuccessful();

    $create = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn ($action): bool => $action instanceof Action && $action->getName() === 'create');

    expect(tableHeaderActionNames($component))->toContain('create')
        ->and($create)->not->toBeNull()
        ->and($create->isIconButton())->toBeTrue()
        ->and($create->getTooltip())->toBe(__('New contribution'));
});

test('disbursements table exposes new disbursement header action', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(DisbursementsPage::class)
        ->assertSuccessful();

    $create = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn ($action): bool => $action instanceof Action && $action->getName() === 'newDisbursement');

    expect(tableHeaderActionNames($component))->toContain('newDisbursement')
        ->and($create)->not->toBeNull()
        ->and($create->isIconButton())->toBeTrue()
        ->and($create->getTooltip())->toBe(__('New disbursement'));
});
