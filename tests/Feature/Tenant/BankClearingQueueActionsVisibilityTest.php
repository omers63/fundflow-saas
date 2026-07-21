<?php

declare(strict_types=1);

use App\Filament\Support\BankClearingQueueActions;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    User::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Queue Actions Admin',
        'email' => 'queue-actions-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->accounting = app(AccountingService::class);
});

/**
 * @return list<string>
 */
function queueActionNames(?string $filter): array
{
    return collect(TableRecordActionGroups::flatten(
        BankClearingQueueActions::groupedRecordActions($filter),
    ))
        ->map(fn (Action $action): string => $action->getName())
        ->unique()
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function queueBulkActionNames(?string $filter): array
{
    return collect(BankClearingQueueActions::toolbarBulkActions($filter))
        ->map(fn ($action): string => $action->getName())
        ->all();
}

test('bank file mode registers posting actions and omits clear', function () {
    $names = queueActionNames(BankClearingTabRegistry::FILTER_BANK_FILE);

    expect($names)->toContain('mirrorToCash', 'postToMember', 'autoMatch', 'ignore', 'delete', 'view')
        ->and($names)->not->toContain('clearWithoutEvidence', 'matchToBankLine', 'deletePendingOperational');

    $bulk = queueBulkActionNames(BankClearingTabRegistry::FILTER_BANK_FILE);

    expect($bulk)->toContain('mirrorSelectedToCash', 'postSelectedToMember', 'ignoreSelected')
        ->and($bulk)->not->toContain('clearWithoutEvidenceBulk');
});

test('operations mode registers match and clear and omits posting', function () {
    $names = queueActionNames(BankClearingTabRegistry::FILTER_OPERATIONS);

    expect($names)->toContain('autoMatch', 'matchToBankLine', 'clearWithoutEvidence', 'deletePendingOperational', 'view')
        ->and($names)->not->toContain('mirrorToCash', 'postToMember', 'ignore');

    $bulk = queueBulkActionNames(BankClearingTabRegistry::FILTER_OPERATIONS);

    expect($bulk)->toContain('clearWithoutEvidenceBulk', 'matchSelected', 'matchAllUnique')
        ->and($bulk)->not->toContain('mirrorSelectedToCash', 'postSelectedToMember', 'ignoreSelected');
});

test('work queue hides posting actions on operations rows and clear on bank file rows', function () {
    $statement = BankStatement::create([
        'filename' => 'visibility.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-10',
        'description' => 'Visibility bank file',
        'amount' => 100,
        'status' => 'imported',
        'hash' => md5('visibility-bank-file'),
    ]);

    $member = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($member);
    $posting = app(FundPostingService::class)->submit($member, 100, '2026-05-10');
    app(FundPostingService::class)->accept($posting);
    $operational = $posting->bankTransaction->fresh();

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['queueFilter' => 'all'])
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
            'queueFilter' => 'all',
        ])
        ->assertSuccessful();

    $component
        ->assertTableActionVisible('mirrorToCash', $imported)
        ->assertTableActionVisible('postToMember', $imported)
        ->assertTableActionVisible('ignore', $imported)
        ->assertTableActionHidden('clearWithoutEvidence', $imported)
        ->assertTableActionHidden('matchToBankLine', $imported);

    $component
        ->assertTableActionVisible('matchToBankLine', $operational)
        ->assertTableActionVisible('clearWithoutEvidence', $operational)
        ->assertTableActionHidden('mirrorToCash', $operational)
        ->assertTableActionHidden('postToMember', $operational)
        ->assertTableActionHidden('ignore', $operational);
});

test('default queue filter prefers sole nonempty slice then bank file when both nonempty', function () {
    expect(BankClearingTabRegistry::defaultQueueFilter([
        'bank_file' => 0,
        'operations' => 0,
    ]))->toBe(BankClearingTabRegistry::FILTER_ALL);

    expect(BankClearingTabRegistry::defaultQueueFilter([
        'bank_file' => 2,
        'operations' => 0,
    ]))->toBe(BankClearingTabRegistry::FILTER_BANK_FILE);

    expect(BankClearingTabRegistry::defaultQueueFilter([
        'bank_file' => 0,
        'operations' => 3,
    ]))->toBe(BankClearingTabRegistry::FILTER_OPERATIONS);

    expect(BankClearingTabRegistry::defaultQueueFilter([
        'bank_file' => 1,
        'operations' => 1,
    ]))->toBe(BankClearingTabRegistry::FILTER_BANK_FILE);
});

test('work queue without queueFilter query uses smart default for operations-only backlog', function () {
    Account::masterFund()?->update(['balance' => 5_000]);

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Default filter reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        150,
        'Default filter expense',
    );

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
        ])
        ->assertSuccessful()
        ->assertSet('queueFilter', BankClearingTabRegistry::FILTER_OPERATIONS);
});

test('row actions sit in a single Actions group with short labels', function () {
    $groups = BankClearingQueueActions::groupedRecordActions(BankClearingTabRegistry::FILTER_BANK_FILE);

    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toBeInstanceOf(ActionGroup::class)
        ->and($groups[0]->isButton())->toBeTrue()
        ->and((string) $groups[0]->getLabel())->toBe(__('Actions'));

    $labels = collect($groups[0]->getActions())
        ->map(fn (Action $action): string => (string) $action->getLabel())
        ->all();

    expect($labels)->toContain(__('Post cash'), __('Post member'), __('Auto-match'), __('View'), __('Ignore'), __('Delete'));
});
