<?php

use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;

it('always registers the import statement header action', function () {
    $page = new ListBankAccounts;

    $method = new ReflectionMethod(ListBankAccounts::class, 'getHeaderActions');
    $method->setAccessible(true);

    $page->activeTab = 'transactions';

    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->getName())->toBe('import');
});

it('hides the import statement action on the transactions tab', function () {
    $page = new ListBankAccounts;
    $page->activeTab = 'transactions';

    $method = new ReflectionMethod(ListBankAccounts::class, 'getHeaderActions');
    $method->setAccessible(true);

    $import = $method->invoke($page)[0];
    $import->livewire($page);

    expect($import->isHidden())->toBeTrue();

    $page->activeTab = 'statements';

    expect($import->isHidden())->toBeFalse();
});
