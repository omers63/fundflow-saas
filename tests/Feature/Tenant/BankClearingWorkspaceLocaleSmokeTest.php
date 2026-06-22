<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Resources\SmsClearing\Pages\ListSmsClearing;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->admin = User::create([
        'name' => 'Bank Clearing Locale Admin',
        'email' => 'bank-clearing-locale-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
});

dataset('bank clearing locales', [
    'english' => ['en'],
    'arabic' => ['ar'],
]);

it('renders bank clearing workspace shell in each locale', function (string $locale) {
    App::setLocale($locale);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->assertSuccessful()
        ->assertSee(__('Work queue'))
        ->assertSee(__('Bank ledger'))
        ->assertSee(__('Import history'))
        ->assertSee(__('Bank clearing workspace'))
        ->assertDontSee(__('SMS clearing workspace'));
})->with('bank clearing locales');

it('renders queue filter chips and balances toggle in each locale', function (string $locale) {
    App::setLocale($locale);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->assertSee(__('All open'))
        ->assertSee(__('From bank file'))
        ->assertSee(__('From operations'))
        ->assertSee(__('Show balances & trends'))
        ->call('toggleQueueBalances')
        ->assertSet('showQueueBalances', true);
})->with('bank clearing locales');

it('renders import history combined sections in each locale', function (string $locale) {
    App::setLocale($locale);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->call('setBankTab', BankClearingTabRegistry::TAB_HISTORY)
        ->assertSee(__('Import batches'))
        ->assertSee(__('Closed statement lines'))
        ->call('toggleClosedHistoryLines')
        ->assertSet('showClosedHistoryLines', true);
})->with('bank clearing locales');

it('renders sms clearing page in each locale', function (string $locale) {
    App::setLocale($locale);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class)
        ->assertSuccessful()
        ->assertSee(__('SMS clearing workspace'))
        ->assertSee(__('Work queue'))
        ->assertSee(__('Posted ledger'))
        ->assertSee(__('Import history'));
})->with('bank clearing locales');
