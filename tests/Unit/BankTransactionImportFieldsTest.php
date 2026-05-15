<?php

use App\Filament\Support\BankTransactionImportFields;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\BankTransaction;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    BankTemplate::query()->delete();
});

it('returns labeled rows from template mappings and raw import data', function (): void {
    $template = BankTemplate::create([
        'name' => 'Test Bank',
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'has_header' => true,
        'skip_rows' => 0,
        'date_format' => 'Y-m-d',
        'date_column' => 'Date',
        'amount_mode' => 'single',
        'amount_column' => 'Amount',
        'extra_columns' => [
            ['key' => 'description', 'column' => 'Description'],
            ['key' => 'reference', 'column' => 'Reference'],
            ['key' => 'branch_code', 'column' => 'Branch'],
        ],
        'duplicate_fields' => ['date', 'amount', 'description'],
        'duplicate_date_tolerance' => 0,
        'is_default' => true,
    ]);

    $statement = BankStatement::create([
        'filename' => 'stmt.csv',
        'bank_template_id' => $template->id,
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $transaction = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-01-15',
        'description' => 'Salary deposit',
        'amount' => 1500,
        'reference' => 'REF-99',
        'status' => 'imported',
        'hash' => md5('import-fields-test'),
        'raw_data' => json_encode([
            'description' => 'Salary deposit',
            'reference' => 'REF-99',
            'branch_code' => 'BR-01',
            '_raw_csv' => ['2026-01-15', 'Salary deposit', '1500', 'REF-99', 'BR-01'],
        ]),
    ]);

    $rows = BankTransactionImportFields::labeledRows($transaction);

    expect($rows)->toHaveKey('Date')
        ->and($rows)->toHaveKey('Amount')
        ->and($rows)->toHaveKey('Description')
        ->and($rows)->toHaveKey('Reference')
        ->and($rows)->toHaveKey('Branch Code')
        ->and($rows['Branch Code'])->toBe('BR-01')
        ->and($rows)->toHaveKey('Raw CSV row');
});
