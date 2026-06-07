<?php

use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Widgets\SmsImportSessionsTableWidget;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

it('registers bank and sms import table header actions', function () {
    expect(BankWorkspaceImportTableHeaderActions::bankStatementImportAction()->getName())->toBe('import')
        ->and(BankWorkspaceImportTableHeaderActions::smsImportAction()->getName())->toBe('importSms');
});

it('shows the bank import action only on the statement lines tab', function () {
    $this->initializeTenancy();

    $admin = User::create([
        'name' => 'Bank Import Admin',
        'email' => 'bank-import-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');

    $importsComponent = Livewire::actingAs($admin, 'tenant')
        ->test(ListBankAccounts::class, ['channel' => 'bank']);

    $importsHeaderNames = collect($importsComponent->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($importsHeaderNames)->toContain('import');

    $ledgerComponent = Livewire::actingAs($admin, 'tenant')
        ->test(ListBankAccounts::class, ['channel' => 'bank', 'activeTab' => 'ledger']);

    $ledgerHeaderNames = collect($ledgerComponent->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($ledgerHeaderNames)->not->toContain('import');
});

it('shows the sms import action on the sms history table widget', function () {
    $this->initializeTenancy();

    $admin = User::create([
        'name' => 'SMS Import Admin',
        'email' => 'sms-table-import@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(SmsImportSessionsTableWidget::class);

    $headerNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerNames)->toContain('importSms');
});
