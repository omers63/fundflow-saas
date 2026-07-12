<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Tenant\Widgets\TransactionsInsightsWidget;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\TransactionBusinessTypeCatalog;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

function tenantTransactionsDomain(): string
{
    return 'testing.localhost';
}

beforeEach(function (): void {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $domain = tenantTransactionsDomain();

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $admin = User::create([
        'name' => 'Transactions Admin',
        'email' => 'transactions-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('transactions page renders for tenant admin', function (): void {
    $this->get('http://'.tenantTransactionsDomain().'/admin/transactions')
        ->assertSuccessful()
        ->assertSee(__('Transactions'), false)
        ->assertSee(__('Browse posted member and master ledger lines from one place.'), false);
});

test('transactions insights widget renders on transactions page', function (): void {
    Livewire::test(ListTransactions::class)
        ->assertSuccessful()
        ->assertSee(trans_choice(':count-day flow trend|:count-day flow trend', 30, ['count' => 30]), false)
        ->assertSee(__('Current view'))
        ->assertSee(__('Scope mix'));
});

test('transactions table amount column includes footer summary', function (): void {
    $component = Livewire::test(ListTransactions::class)
        ->assertSuccessful();

    $amountColumn = collect($component->instance()->getTable()->getColumns())
        ->first(fn ($column) => $column->getName() === 'amount');

    expect($amountColumn)->not->toBeNull()
        ->and($amountColumn->getSummarizers())->toHaveCount(1)
        ->and($amountColumn->getSummarizers()[0]->getLabel())->toBe(__('Amount'));
});

test('transactions table sorts ledger amounts by signed value', function (): void {
    $member = Member::create([
        'member_number' => 'MEM-TXN-SIGNED',
        'name' => 'Signed Sort Member',
        'email' => 'signed-sort@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $credit = Transaction::create([
        'account_id' => $member->cashAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Signed sort credit',
        'transacted_at' => now()->subMinutes(3),
    ]);

    $smallDebit = Transaction::create([
        'account_id' => $member->cashAccount->id,
        'member_id' => $member->id,
        'type' => 'debit',
        'amount' => 50,
        'balance_after' => 50,
        'description' => 'Signed sort debit small',
        'transacted_at' => now()->subMinutes(2),
    ]);

    $largeDebit = Transaction::create([
        'account_id' => $member->cashAccount->id,
        'member_id' => $member->id,
        'type' => 'debit',
        'amount' => 200,
        'balance_after' => 0,
        'description' => 'Signed sort debit large',
        'transacted_at' => now()->subMinute(),
    ]);

    expect(
        Transaction::query()
            ->where('member_id', $member->id)
            ->orderBySignedAmount('asc')
            ->pluck('id')
            ->all(),
    )->toBe([$largeDebit->id, $smallDebit->id, $credit->id])
        ->and(
            Transaction::query()
                ->where('member_id', $member->id)
                ->orderBySignedAmount('desc')
                ->pluck('id')
                ->all(),
        )->toBe([$credit->id, $smallDebit->id, $largeDebit->id]);
});

test('transactions table filters by business type class and account type', function (): void {
    $member = Member::create([
        'member_number' => 'MEM-TXN-01',
        'name' => 'Transactions Member',
        'email' => 'transactions-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::create([
        'type' => 'cash',
        'name' => 'Master Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    $depositTransaction = Transaction::create([
        'account_id' => $member->cashAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 500,
        'reference_type' => FundPosting::class,
        'reference_id' => 1001,
        'description' => 'Deposit accepted',
        'transacted_at' => now()->subMinutes(3),
    ]);

    $fundTransaction = Transaction::create([
        'account_id' => $member->fundAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 300,
        'balance_after' => 300,
        'reference_type' => Contribution::class,
        'reference_id' => 1002,
        'description' => 'Contribution posted',
        'transacted_at' => now()->subMinutes(2),
    ]);

    $manualMasterTransaction = Transaction::create([
        'account_id' => $masterCash->id,
        'member_id' => null,
        'type' => 'credit',
        'amount' => 200,
        'balance_after' => 200,
        'reference_type' => null,
        'reference_id' => null,
        'description' => 'Manual cash adjustment',
        'transacted_at' => now()->subMinute(),
    ]);

    Livewire::test(ListTransactions::class)
        ->filterTable('business_type', TransactionBusinessTypeCatalog::DEPOSIT)
        ->assertCanSeeTableRecords([$depositTransaction])
        ->assertCanNotSeeTableRecords([$fundTransaction, $manualMasterTransaction])
        ->resetTableFilters()
        ->filterTable('account_class', 'master')
        ->assertCanSeeTableRecords([$manualMasterTransaction])
        ->assertCanNotSeeTableRecords([$depositTransaction, $fundTransaction])
        ->resetTableFilters()
        ->filterTable('account_type', 'fund')
        ->assertCanSeeTableRecords([$fundTransaction])
        ->assertCanNotSeeTableRecords([$depositTransaction, $manualMasterTransaction]);
});

test('transactions table sorts scope column without ordering by a synthetic sql column', function (): void {
    $member = Member::create([
        'member_number' => 'MEM-TXN-02',
        'name' => 'Sort Transactions Member',
        'email' => 'transactions-sort-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::create([
        'type' => 'cash',
        'name' => 'Master Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    $memberTransaction = Transaction::create([
        'account_id' => $member->cashAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Sort tx member row',
        'transacted_at' => now()->subMinutes(2),
    ]);

    $masterTransaction = Transaction::create([
        'account_id' => $masterCash->id,
        'member_id' => null,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Sort tx master row',
        'transacted_at' => now()->subMinute(),
    ]);

    Livewire::test(ListTransactions::class)
        ->searchTable('Sort tx')
        ->sortTable('account_scope', 'desc')
        ->assertCanSeeTableRecords([$masterTransaction, $memberTransaction], inOrder: true);
});

test('transactions insights react to table filters and search text', function (): void {
    $member = Member::create([
        'member_number' => 'MEM-TXN-03',
        'name' => 'Insights Transactions Member',
        'email' => 'transactions-insights-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::create([
        'type' => 'cash',
        'name' => 'Insights Master Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    Transaction::create([
        'account_id' => $member->fundAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 300,
        'balance_after' => 300,
        'reference_type' => Contribution::class,
        'reference_id' => 2001,
        'description' => 'Insights alpha contribution A',
        'transacted_at' => now()->subMinutes(3),
    ]);

    Transaction::create([
        'account_id' => $member->fundAccount->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 150,
        'balance_after' => 450,
        'reference_type' => Contribution::class,
        'reference_id' => 2002,
        'description' => 'Insights alpha contribution B',
        'transacted_at' => now()->subMinutes(2),
    ]);

    Transaction::create([
        'account_id' => $masterCash->id,
        'member_id' => null,
        'type' => 'credit',
        'amount' => 200,
        'balance_after' => 200,
        'reference_type' => null,
        'reference_id' => null,
        'description' => 'Insights beta manual',
        'transacted_at' => now()->subMinute(),
    ]);

    Livewire::test(TransactionsInsightsWidget::class, [
        'tableSearch' => 'Insights',
    ])
        ->assertSee(__('All scopes'))
        ->assertSee(__('Contribution'));

    Livewire::test(TransactionsInsightsWidget::class, [
        'tableSearch' => 'Insights',
        'tableFilters' => [
            'account_class' => ['value' => 'master'],
        ],
    ])
        ->assertSee(__('Manual / unlinked'))
        ->assertSee(__('Master'));

    Livewire::test(TransactionsInsightsWidget::class, [
        'tableSearch' => 'beta manual',
    ])
        ->assertSee(__('Manual / unlinked'))
        ->assertDontSee(__('Contribution'));
});
