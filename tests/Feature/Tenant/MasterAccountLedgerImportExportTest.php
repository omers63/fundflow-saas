<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Tables\MasterBankLedgerTable;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\AccountTransactionExportService;
use App\Services\AccountTransactionImportService;
use App\Services\MasterInvestInService;
use App\Services\MasterInvestOutService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();

    $admin = User::create([
        'name' => 'Ledger Import Admin',
        'email' => 'ledger-import-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');
});

test('master expense ledger export uses credit and debit type values', function () {
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
        ->toContain(',credit,');
});

test('master ledger import posts manual credits and debits for cash with transaction date', function () {
    $cash = Account::factory()->masterCash()->withBalance(0)->create();
    $member = Member::factory()->create(['member_number' => 'TAG-001']);

    $path = storage_path('app/master-ledger-import-test.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'member_number']);
    fputcsv($handle, ['2026-06-01 10:00:00', 'credit', '25.50', 'Imported credit', 'TAG-001']);
    fputcsv($handle, ['2026-06-02 11:00:00', 'debit', '10', 'Imported debit', '']);
    fclose($handle);

    $result = app(AccountTransactionImportService::class)->import($cash, $path);

    $credit = $cash->transactions()->where('description', 'Imported credit')->first();

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and((float) $cash->fresh()->balance)->toBe(15.5)
        ->and($cash->transactions()->count())->toBe(2)
        ->and($credit?->member_id)->toBe($member->id)
        ->and($credit?->transacted_at?->toDateTimeString())->toBe('2026-06-01 10:00:00');

    @unlink($path);
});

test('master invest export omits internal transfer legs', function () {
    Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestOutService::class)->investOut($invest, 1_000, 'Export placement');
    app(MasterInvestInService::class)->investIn($invest, 400, 'Export return');

    ob_start();
    app(AccountTransactionExportService::class)->downloadCsv($invest->fresh())->sendContent();
    $csv = (string) ob_get_clean();

    expect($invest->transactions()->count())->toBe(4)
        ->and(substr_count($csv, ',credit,'))->toBe(1)
        ->and(substr_count($csv, ',debit,'))->toBe(1)
        ->and($csv)->toContain('(investment return)')
        ->and($csv)->toContain('(invest out)')
        ->and($csv)->not->toContain('(reserve funding)')
        ->and($csv)->not->toContain('(reserve return)');
});

test('legacy full invest export imports only return and disbursement legs', function () {
    Account::factory()->masterFund()->withBalance(50_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $sourceInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestOutService::class)->investOut($sourceInvest, 2_500, 'Legacy placement');
    app(MasterInvestInService::class)->investIn($sourceInvest, 900, 'Legacy return');

    $disbursement = $sourceInvest->transactions()
        ->where('reference_type', InvestDisbursement::class)
        ->firstOrFail();
    $investReturn = $sourceInvest->transactions()
        ->where('reference_type', InvestReturn::class)
        ->where('type', 'credit')
        ->firstOrFail();
    $reserveFunding = $sourceInvest->transactions()
        ->where('type', 'credit')
        ->whereNull('reference_type')
        ->firstOrFail();
    $reserveReturn = $sourceInvest->transactions()
        ->where('reference_type', InvestReturn::class)
        ->where('type', 'debit')
        ->firstOrFail();

    $path = storage_path('app/master-invest-legacy-full-export.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'reference_type', 'reference_id']);
    fputcsv($handle, [
        $reserveFunding->transacted_at?->toDateTimeString(),
        $reserveFunding->type,
        $reserveFunding->amount,
        $reserveFunding->description,
        '',
        '',
    ]);
    fputcsv($handle, [
        $disbursement->transacted_at?->toDateTimeString(),
        $disbursement->type,
        $disbursement->amount,
        $disbursement->description,
        $disbursement->reference_type,
        $disbursement->reference_id,
    ]);
    fputcsv($handle, [
        $investReturn->transacted_at?->toDateTimeString(),
        $investReturn->type,
        $investReturn->amount,
        $investReturn->description,
        $investReturn->reference_type,
        $investReturn->reference_id,
    ]);
    fputcsv($handle, [
        $reserveReturn->transacted_at?->toDateTimeString(),
        $reserveReturn->type,
        $reserveReturn->amount,
        $reserveReturn->description,
        $reserveReturn->reference_type,
        $reserveReturn->reference_id,
    ]);
    fclose($handle);

    $targetInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    $result = app(AccountTransactionImportService::class)->import($targetInvest, $path);

    expect($result['created'])->toBe(2)
        ->and($result['skipped'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($targetInvest->fresh()->transactions()->count())->toBe(4);

    @unlink($path);
});

test('invest import skips rows when business reference already exists without transaction ids', function () {
    Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestOutService::class)->investOut($invest, 500, 'Reference skip placement');
    app(MasterInvestInService::class)->investIn($invest, 200, 'Reference skip return');

    $disbursement = InvestDisbursement::query()->latest('id')->first();
    $investReturn = InvestReturn::query()->latest('id')->first();

    $path = storage_path('app/master-invest-reference-skip.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'reference_type', 'reference_id']);
    fputcsv($handle, [
        '2026-06-01 10:00:00',
        'debit',
        '500',
        'Invest disbursement #'.$disbursement->id.' – Reference skip placement (invest out)',
        InvestDisbursement::class,
        (string) $disbursement->id,
    ]);
    fputcsv($handle, [
        '2026-06-15 11:00:00',
        'credit',
        '200',
        'Invest return #'.$investReturn->id.' – Reference skip return (investment return)',
        InvestReturn::class,
        (string) $investReturn->id,
    ]);
    fclose($handle);

    $beforeCount = $invest->transactions()->count();

    $result = app(AccountTransactionImportService::class)->import($invest->fresh(), $path);

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($invest->fresh()->transactions()->count())->toBe($beforeCount);

    @unlink($path);
});

test('re-importing exported invest ledger skips existing rows without double posting', function () {
    Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestOutService::class)->investOut($invest, 800, 'Round trip placement');
    app(MasterInvestInService::class)->investIn($invest, 300, 'Round trip return');

    $path = storage_path('app/master-invest-round-trip.csv');
    ob_start();
    app(AccountTransactionExportService::class)->downloadCsv($invest->fresh())->sendContent();
    file_put_contents($path, (string) ob_get_clean());

    $beforeCount = $invest->transactions()->count();
    $beforeBankCount = BankTransaction::query()->count();

    $result = app(AccountTransactionImportService::class)->import($invest->fresh(), $path);

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($invest->fresh()->transactions()->count())->toBe($beforeCount)
        ->and(BankTransaction::query()->count())->toBe($beforeBankCount);

    @unlink($path);
});

test('master invest ledger import runs debit and credit workflows with transaction dates', function () {
    Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    $path = storage_path('app/master-invest-ledger-import-test.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'member_number']);
    fputcsv($handle, ['2026-06-01 10:00:00', 'debit', '5000', 'Imported placement', '']);
    fputcsv($handle, ['2026-06-15 11:00:00', 'credit', '1200', 'Imported return', '']);
    fclose($handle);

    $result = app(AccountTransactionImportService::class)->import($invest, $path);

    $placementDebit = $invest->transactions()
        ->where('type', 'debit')
        ->where('description', 'like', '%Imported placement%')
        ->first();

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and((float) $invest->fresh()->balance)->toBe(0.0)
        ->and($invest->transactions()->count())->toBe(4)
        ->and(BankTransaction::query()->count())->toBe(2)
        ->and($placementDebit?->transacted_at?->toDateTimeString())->toBe('2026-06-01 10:00:00')
        ->and(BankTransaction::query()->orderBy('id')->value('transaction_date')?->toDateString())->toBe('2026-06-01');

    @unlink($path);
});

test('master expense ledger import accepts credit using transaction_date column alias', function () {
    Account::factory()->masterFund()->withBalance(10_000)->create();
    $expense = Account::factory()->masterExpense()->withBalance(0)->create();

    $path = storage_path('app/master-expense-ledger-import-test.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transaction_date', 'type', 'amount', 'description', 'member_number']);
    fputcsv($handle, ['2026-03-20 09:30:00', 'credit', '2500', 'Imported funding', '']);
    fclose($handle);

    $result = app(AccountTransactionImportService::class)->import($expense, $path);

    $transaction = $expense->transactions()->where('description', 'like', '%Imported funding%')->first();

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and((float) $expense->fresh()->balance)->toBe(2_500.0)
        ->and($transaction?->transacted_at?->toDateTimeString())->toBe('2026-03-20 09:30:00');

    @unlink($path);
});

test('master invest import rejects unknown type values', function () {
    Account::factory()->masterFund()->withBalance(20_000)->create();
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    $path = storage_path('app/master-invest-invalid-import-test.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['transacted_at', 'type', 'amount', 'description', 'member_number']);
    fputcsv($handle, ['2026-06-01 10:00:00', 'deposit', '100', 'Should fail', '']);
    fclose($handle);

    $result = app(AccountTransactionImportService::class)->import($invest, $path);

    expect($result['created'])->toBe(0)
        ->and($result['failed'])->toBe(1);

    @unlink($path);
});

test('master bank ledger table exposes import export and manual actions for admins', function () {
    Account::factory()->masterBank()->create();

    $names = collect(MasterBankLedgerTable::headerActions())
        ->map->getName()
        ->all();

    expect($names)->toContain('importLedger', 'exportLedger', 'manualCredit', 'manualDebit');
});
