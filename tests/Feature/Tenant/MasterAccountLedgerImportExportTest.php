<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Tables\MasterBankLedgerTable;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\AccountTransactionExportService;
use App\Services\AccountTransactionImportService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
});

test('master expense ledger export includes transaction rows', function () {
    $expense = Account::factory()->masterExpense()->withBalance(100)->create();

    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->postManualCredit($expense, 40, 'Exported credit', now()),
    );

    ob_start();
    app(AccountTransactionExportService::class)->downloadCsv($expense->fresh())->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)
        ->toContain('transacted_at')
        ->toContain('Exported credit')
        ->toContain('credit');
});

test('master ledger import posts manual credits and debits', function () {
    $admin = User::create([
        'name' => 'Ledger Import Admin',
        'email' => 'ledger-import-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $cash = Account::factory()->masterCash()->withBalance(0)->create();
    $member = Member::factory()->create(['member_number' => 'TAG-001']);

    $path = storage_path('app/master-ledger-import-test.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'member_number']);
    fputcsv($handle, ['2026-06-01 10:00:00', 'credit', '25.50', 'Imported credit', 'TAG-001']);
    fputcsv($handle, ['2026-06-02 11:00:00', 'debit', '10', 'Imported debit', '']);
    fclose($handle);

    $result = app(AccountTransactionImportService::class)->import($cash, $path);

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and((float) $cash->fresh()->balance)->toBe(15.5)
        ->and($cash->transactions()->count())->toBe(2)
        ->and($cash->transactions()->where('description', 'Imported credit')->first()?->member_id)->toBe($member->id);

    @unlink($path);
});

test('master bank ledger table exposes import export and manual actions for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-master-bank-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    Account::factory()->masterBank()->create();

    $names = collect(MasterBankLedgerTable::headerActions())
        ->map->getName()
        ->all();

    expect($names)->toContain('importLedger', 'exportLedger', 'manualCredit', 'manualDebit');
});
