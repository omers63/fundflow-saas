<?php

declare(strict_types=1);

use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ViewMasterAccount;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\PendingOperationalClearanceRelationManager;
use App\Filament\Tenant\Resources\SmsClearing\Pages\ListSmsClearing;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Bank Preview Admin',
        'email' => 'bank-preview-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->accounting = app(AccountingService::class);
});

test('master account pending bank match preview is read only with deep link', function () {
    $member = Member::create([
        'member_number' => 'MEM-PREVIEW',
        'name' => 'Preview Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 500, now()->toDateString());
    app(FundPostingService::class)->accept($posting);

    $masterCash = Account::masterCash();

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(PendingOperationalClearanceRelationManager::class, [
            'ownerRecord' => $masterCash,
            'pageClass' => ViewMasterAccount::class,
        ])
        ->assertSuccessful();

    $table = $component->instance()->getTable();

    $actionNames = collect(TableRecordActionGroups::flatten($table->getRecordActions()))
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($actionNames)->not->toContain('matchToBankLine')
        ->and($actionNames)->not->toContain('clearWithoutEvidence')
        ->and(collect($table->getHeaderActions())->map->getName()->all())->toContain('openBankClearingWorkspace');
});

test('legacy bank accounts sms url resolves to sms clearing page', function () {
    $url = BankAccountsResource::listUrl(channel: 'sms', smsSubTab: 'history');

    expect($url)->toContain('sms-imports')
        ->and($url)->toContain('tab=history')
        ->and($url)->not->toContain('channel=sms');
});

test('bank accounts list redirects legacy sms channel to sms clearing page', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['channel' => 'sms', 'smsSubTab' => 'transactions'])
        ->test(ListBankAccounts::class)
        ->assertRedirect(SmsClearingResource::getUrl('index'));
});

test('sms clearing page renders queue workspace', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListSmsClearing::class)
        ->assertSuccessful()
        ->assertSee(__('SMS clearing workspace'))
        ->assertSee(__('Work queue'))
        ->assertSet('activeTab', 'queue');
});

test('import history tab opens closed lines from legacy history section url', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'activeTab' => 'history',
            'historySection' => 'closed',
        ])
        ->assertSet('showClosedHistoryLines', true)
        ->assertSet('historySection', BankClearingTabRegistry::HISTORY_CLOSED);
});

test('bank clearing page no longer shows sms channel toggle', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->assertSuccessful()
        ->assertDontSee(__('SMS clearing workspace'))
        ->assertSee(__('Work queue'));
});
