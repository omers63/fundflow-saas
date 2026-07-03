<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Transaction;
use App\Support\MasterInvestLedgerImport;
use Tests\TestCase;

uses(TestCase::class);

test('invest export scope omits internal fund transfer legs', function () {
    $invest = Account::factory()->masterInvest()->create();

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 100,
        'description' => 'Return (investment return)',
        'reference_type' => InvestReturn::class,
        'reference_id' => 1,
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 100,
        'description' => 'Return (reserve return)',
        'reference_type' => InvestReturn::class,
        'reference_id' => 1,
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 200,
        'description' => 'Placement (reserve funding)',
        'reference_type' => InvestDisbursement::class,
        'reference_id' => 1,
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 200,
        'description' => 'Placement (invest out)',
        'reference_type' => InvestDisbursement::class,
        'reference_id' => 1,
    ]);

    $ids = MasterInvestLedgerImport::applyExportableScope(Transaction::query()->where('account_id', $invest->id))
        ->pluck('type', 'id')
        ->all();

    expect($ids)->toHaveCount(2)
        ->and(collect($ids)->filter(fn (string $type): bool => $type === 'credit')->count())->toBe(1)
        ->and(collect($ids)->filter(fn (string $type): bool => $type === 'debit')->count())->toBe(1);
});

test('invest import skips existing ids and internal legs', function () {
    $invest = Account::factory()->masterInvest()->create();

    $existing = Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 50,
        'description' => 'Existing return',
    ]);

    expect(MasterInvestLedgerImport::shouldSkipImportRow($invest, [
        'id' => (string) $existing->id,
        'type' => 'credit',
        'description' => 'Existing return',
    ]))->toBeTrue()
        ->and(MasterInvestLedgerImport::shouldSkipImportRow($invest, [
            'type' => 'credit',
            'description' => 'Round trip placement (تمويل الاحتياطي)',
        ]))->toBeTrue()
        ->and(MasterInvestLedgerImport::shouldSkipImportRow($invest, [
            'type' => 'credit',
            'description' => 'Funding (reserve funding)',
            'reference_type' => InvestDisbursement::class,
            'reference_id' => '3',
        ]))->toBeTrue()
        ->and(MasterInvestLedgerImport::shouldSkipImportRow($invest, [
            'type' => 'debit',
            'description' => 'Transfer (reserve return)',
            'reference_type' => InvestReturn::class,
            'reference_id' => '9',
        ]))->toBeTrue()
        ->and(MasterInvestLedgerImport::shouldSkipImportRow($invest, [
            'type' => 'debit',
            'description' => 'Placement (invest out)',
            'reference_type' => InvestDisbursement::class,
            'reference_id' => '3',
        ]))->toBeFalse();
});

test('invest import description sanitizer strips exported ledger prefixes and suffixes', function () {
    expect(MasterInvestLedgerImport::sanitizeInvestImportDescription(
        'Invest return #33 – Round trip return (investment return)',
    ))->toBe('Round trip return')
        ->and(MasterInvestLedgerImport::sanitizeInvestImportDescription(
            'عائد استثمار #33 – Round trip return (عائد استثمار)',
        ))->toBe('Round trip return');
});
