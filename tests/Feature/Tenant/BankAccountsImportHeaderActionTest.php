<?php

use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;

it('always registers the import statement header action', function () {
    $page = new ListBankAccounts;

    $method = new ReflectionMethod(ListBankAccounts::class, 'getHeaderActions');
    $method->setAccessible(true);

    $page->activeTab = 'imports';

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->getName())->toBe('import');
});

it('shows the import statement action on every bank accounts tab', function () {
    $page = new ListBankAccounts;

    $method = new ReflectionMethod(ListBankAccounts::class, 'getHeaderActions');
    $method->setAccessible(true);

    $import = $method->invoke($page)[0];
    $import->livewire($page);

    foreach (['imports', 'ledger', 'statements'] as $tab) {
        $page->activeTab = $tab;

        expect($import->isHidden())->toBeFalse("import should be visible on tab {$tab}");
    }
});
