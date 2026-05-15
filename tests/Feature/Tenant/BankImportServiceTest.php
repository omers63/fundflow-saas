<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\BankImportService;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->service = app(BankImportService::class);

    Account::query()->delete();
    BankStatement::query()->delete();
    BankTransaction::query()->delete();
    Setting::query()->delete();

    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
});

function createCsvFile(string $content, string $name = 'statement.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

function defaultTemplate(array $overrides = []): array
{
    return array_merge([
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'has_header' => true,
        'skip_rows' => 0,
        'date_format' => 'Y-m-d',
        'amount_mode' => 'single',
        'columns' => [
            'date' => 0,
            'description' => 1,
            'amount' => 2,
            'reference' => 3,
        ],
        'duplicate_fields' => ['date', 'amount', 'description', 'reference'],
        'duplicate_date_tolerance' => 0,
    ], $overrides);
}

test('imports CSV with default template', function () {
    $csv = "date,description,amount,reference\n"
        ."2026-05-01,Deposit from John,5000,REF001\n"
        ."2026-05-02,Deposit from Jane,3000,REF002\n"
        ."2026-05-03,Loan payout,-10000,REF003\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file);

    expect($result['imported'])->toBe(3);
    expect($result['duplicates'])->toBe(0);
    expect($result['statement']->status)->toBe('completed');
    expect($result['statement']->total_rows)->toBe(3);

    expect(BankTransaction::count())->toBe(3);
    expect(BankTransaction::where('amount', '>', 0)->count())->toBe(2);
    expect(BankTransaction::where('amount', '<', 0)->count())->toBe(1);
});

test('detects duplicate transactions and stores them', function () {
    $csv = "date,description,amount,reference\n"
        ."2026-05-01,Deposit from John,5000,REF001\n";

    $file1 = createCsvFile($csv);
    $this->service->importCsv($file1);

    $file2 = createCsvFile($csv);
    $result = $this->service->importCsv($file2);

    expect($result['imported'])->toBe(0);
    expect($result['duplicates'])->toBe(1);
    expect(BankTransaction::count())->toBe(2);

    $duplicate = BankTransaction::where('status', 'duplicate')->first();
    expect($duplicate)->not->toBeNull();
    expect($duplicate->duplicate_of_id)->toBe(BankTransaction::where('status', 'imported')->first()->id);
});

test('all imported transactions have imported status', function () {
    $csv = "date,description,amount,reference\n"
        ."2026-05-01,Deposit,1000,REF001\n";

    $file = createCsvFile($csv);
    $this->service->importCsv($file);

    expect(BankTransaction::first()->status)->toBe('imported');
});

test('uses custom CSV template from settings', function () {
    Setting::set('bank', 'csv_template', json_encode([
        'delimiter' => ';',
        'has_header' => true,
        'skip_rows' => 0,
        'date_format' => 'd/m/Y',
        'columns' => [
            'date' => 0,
            'description' => 1,
            'amount' => 2,
            'reference' => 3,
        ],
    ]));

    $csv = "date;description;amount;reference\n"
        ."01/05/2026;Deposit from John;5000;REF001\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    expect($txn->description)->toBe('Deposit from John');
    expect($txn->amount)->toBe('5000.00');
    expect($txn->transaction_date->format('Y-m-d'))->toBe('2026-05-01');
});

test('skips rows with zero amount', function () {
    $csv = "date,description,amount,reference\n"
        ."2026-05-01,Empty row,0,REF001\n"
        ."2026-05-02,Valid deposit,1000,REF002\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file);

    expect($result['imported'])->toBe(1);
});

test('creates bank statement record with metadata', function () {
    $csv = "date,description,amount,reference\n"
        ."2026-05-01,Deposit,1000,REF001\n"
        ."2026-05-10,Deposit,2000,REF002\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, bankName: 'Test Bank');

    $statement = $result['statement'];
    expect($statement->filename)->toBe('statement.csv');
    expect($statement->bank_name)->toBe('Test Bank');
    expect($statement->total_rows)->toBe(2);
    expect($statement->imported_rows)->toBe(2);
    expect($statement->duplicate_rows)->toBe(0);
    expect($statement->status)->toBe('completed');
    expect($statement->imported_at)->not->toBeNull();
});

// --- Split credit/debit columns ---

test('imports CSV with split credit/debit columns', function () {
    $template = defaultTemplate([
        'amount_mode' => 'split',
        'columns' => [
            'date' => 0,
            'description' => 1,
            'credit' => 2,
            'debit' => 3,
            'reference' => 4,
        ],
    ]);

    $csv = "date,description,credit,debit,reference\n"
        ."2026-05-01,Deposit,5000,,REF001\n"
        ."2026-05-02,Withdrawal,,3000,REF002\n"
        ."2026-05-03,Transfer,1000,,REF003\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(3);

    $credits = BankTransaction::where('amount', '>', 0)->get();
    $debits = BankTransaction::where('amount', '<', 0)->get();

    expect($credits)->toHaveCount(2);
    expect($debits)->toHaveCount(1);
    expect($debits->first()->amount)->toBe('-3000.00');
});

test('split mode treats empty credit and debit as zero and skips row', function () {
    $template = defaultTemplate([
        'amount_mode' => 'split',
        'columns' => [
            'date' => 0,
            'description' => 1,
            'credit' => 2,
            'debit' => 3,
        ],
    ]);

    $csv = "date,description,credit,debit\n"
        ."2026-05-01,Empty row,,\n"
        ."2026-05-02,Valid credit,1000,\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);
});

// --- Extra column mappings ---

test('maps reserved extra columns to main fields', function () {
    $template = defaultTemplate([
        'columns' => [
            'date' => 0,
            'amount' => 1,
            'description' => 2,
            'reference' => 3,
            'balance' => 4,
        ],
    ]);

    $csv = "date,amount,desc,ref,balance\n"
        ."2026-05-01,5000,Deposit,REF001,15000\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    expect($txn->description)->toBe('Deposit');
    expect($txn->reference)->toBe('REF001');
});

test('stores custom extra columns in raw_data', function () {
    $template = defaultTemplate([
        'columns' => [
            'date' => 0,
            'amount' => 1,
            'description' => 2,
            'branch_code' => 3,
            'teller_id' => 4,
        ],
    ]);

    $csv = "date,amount,description,branch,teller\n"
        ."2026-05-01,5000,Deposit,BR001,T42\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    $rawData = json_decode($txn->raw_data, true);
    expect($rawData['branch_code'])->toBe('BR001');
    expect($rawData['teller_id'])->toBe('T42');
});

// --- Duplicate detection with configurable fields ---

test('duplicate detection with only date and amount', function () {
    $template = defaultTemplate([
        'duplicate_fields' => ['date', 'amount'],
    ]);

    $csv1 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit A,5000,REF001\n";

    $csv2 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit B,5000,REF002\n";

    $file1 = createCsvFile($csv1);
    $this->service->importCsv($file1, template: $template);

    $file2 = createCsvFile($csv2);
    $result = $this->service->importCsv($file2, template: $template);

    expect($result['duplicates'])->toBe(1);
    expect(BankTransaction::count())->toBe(2);
    expect(BankTransaction::where('status', 'duplicate')->count())->toBe(1);
});

test('different duplicate_fields allow previously blocked transactions', function () {
    $strict = defaultTemplate([
        'duplicate_fields' => ['date', 'amount', 'description', 'reference'],
    ]);

    $csv1 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit A,5000,REF001\n";
    $csv2 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit B,5000,REF002\n";

    $file1 = createCsvFile($csv1);
    $this->service->importCsv($file1, template: $strict);

    $file2 = createCsvFile($csv2);
    $result = $this->service->importCsv($file2, template: $strict);

    expect($result['imported'])->toBe(1);
    expect(BankTransaction::count())->toBe(2);
});

// --- Date tolerance ---

test('date tolerance matches nearby dates as duplicates', function () {
    $template = defaultTemplate([
        'duplicate_fields' => ['date', 'amount', 'description'],
        'duplicate_date_tolerance' => 2,
    ]);

    $csv1 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit,5000,REF001\n";
    $csv2 = "date,description,amount,reference\n"
        ."2026-05-02,Deposit,5000,REF002\n";

    $file1 = createCsvFile($csv1);
    $this->service->importCsv($file1, template: $template);

    $file2 = createCsvFile($csv2);
    $result = $this->service->importCsv($file2, template: $template);

    expect($result['duplicates'])->toBe(1);
    expect(BankTransaction::count())->toBe(2);
    expect(BankTransaction::where('status', 'duplicate')->count())->toBe(1);
});

test('date tolerance zero requires exact date match', function () {
    $template = defaultTemplate([
        'duplicate_fields' => ['date', 'amount', 'description'],
        'duplicate_date_tolerance' => 0,
    ]);

    $csv1 = "date,description,amount,reference\n"
        ."2026-05-01,Deposit,5000,REF001\n";
    $csv2 = "date,description,amount,reference\n"
        ."2026-05-02,Deposit,5000,REF002\n";

    $file1 = createCsvFile($csv1);
    $this->service->importCsv($file1, template: $template);

    $file2 = createCsvFile($csv2);
    $result = $this->service->importCsv($file2, template: $template);

    expect($result['imported'])->toBe(1);
    expect(BankTransaction::count())->toBe(2);
});

test('duplicate detection includes custom extra column values in hash', function () {
    $template = defaultTemplate([
        'has_header' => false,
        'columns' => [
            'date' => 0,
            'amount' => 1,
            'description' => 2,
            'ledger_balance' => 3,
        ],
        'duplicate_fields' => ['date', 'amount', 'description', 'ledger_balance'],
    ]);

    $csv1 = "2026-05-01,5000,Deposit,98170.00\n";
    $csv2 = "2026-05-01,5000,Deposit,106170.00\n";

    $this->service->importCsv(createCsvFile($csv1), template: $template);
    $result = $this->service->importCsv(createCsvFile($csv2), template: $template);

    expect($result['imported'])->toBe(1);
    expect($result['duplicates'])->toBe(0);
    expect(BankTransaction::where('status', 'duplicate')->count())->toBe(0);
    expect(BankTransaction::count())->toBe(2);
});

// --- File encoding ---

test('imports ISO-8859-1 encoded CSV', function () {
    $template = defaultTemplate([
        'encoding' => 'ISO-8859-1',
    ]);

    $utf8Content = "date,description,amount,reference\n"
        ."2026-05-01,Dépôt François,5000,REF001\n";

    $isoContent = mb_convert_encoding($utf8Content, 'ISO-8859-1', 'UTF-8');

    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $isoContent);
    $file = new UploadedFile($path, 'statement.csv', 'text/csv', null, true);

    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    expect($txn->description)->toBe('Dépôt François');
});

test('imports Windows-1252 encoded CSV', function () {
    $template = defaultTemplate([
        'encoding' => 'Windows-1252',
    ]);

    $utf8Content = "date,description,amount,reference\n"
        ."2026-05-01,Über Straße,5000,REF001\n";

    $winContent = mb_convert_encoding($utf8Content, 'Windows-1252', 'UTF-8');

    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $winContent);
    $file = new UploadedFile($path, 'statement.csv', 'text/csv', null, true);

    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    expect($txn->description)->toBe('Über Straße');
});

// --- BankTemplate model toTemplateArray ---

test('BankTemplate toTemplateArray produces correct format for single mode', function () {
    $t = BankTemplate::create([
        'name' => 'Test',
        'encoding' => 'ISO-8859-1',
        'delimiter' => ';',
        'has_header' => true,
        'skip_rows' => 1,
        'date_format' => 'd/m/Y',
        'date_column' => 'Date',
        'amount_column' => 'Amount',
        'amount_mode' => 'single',
        'extra_columns' => [
            ['key' => 'description', 'column' => 'Details'],
            ['key' => 'reference', 'column' => 'Ref'],
            ['key' => 'branch_code', 'column' => 'Branch'],
        ],
        'duplicate_fields' => ['date', 'amount'],
        'duplicate_date_tolerance' => 1,
        'is_default' => false,
    ]);

    $arr = $t->toTemplateArray();

    expect($arr['encoding'])->toBe('ISO-8859-1');
    expect($arr['delimiter'])->toBe(';');
    expect($arr['amount_mode'])->toBe('single');
    expect($arr['columns']['date'])->toBe('Date');
    expect($arr['columns']['amount'])->toBe('Amount');
    expect($arr['columns']['description'])->toBe('Details');
    expect($arr['columns']['reference'])->toBe('Ref');
    expect($arr['columns']['branch_code'])->toBe('Branch');
    expect($arr['duplicate_fields'])->toBe(['date', 'amount']);
    expect($arr['duplicate_date_tolerance'])->toBe(1);
});

test('BankTemplate toTemplateArray applies default description and reference when extra_columns empty', function () {
    $t = BankTemplate::create([
        'name' => 'Minimal',
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'has_header' => true,
        'skip_rows' => 0,
        'date_format' => 'Y-m-d',
        'date_column' => '0',
        'amount_column' => '2',
        'amount_mode' => 'single',
        'extra_columns' => [],
        'duplicate_fields' => ['date', 'amount'],
        'duplicate_date_tolerance' => 0,
        'is_default' => false,
    ]);

    $arr = $t->toTemplateArray();

    expect($arr['columns']['description'])->toBe(1);
    expect($arr['columns']['reference'])->toBe(3);
});

test('BankTemplate toTemplateArray produces correct format for split mode', function () {
    $t = BankTemplate::create([
        'name' => 'Split Template',
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'has_header' => false,
        'skip_rows' => 0,
        'date_format' => 'Y-m-d',
        'date_column' => '0',
        'amount_column' => null,
        'amount_mode' => 'split',
        'credit_column' => '2',
        'debit_column' => '3',
        'extra_columns' => [
            ['key' => 'description', 'column' => '1'],
        ],
        'duplicate_fields' => ['date', 'amount', 'description'],
        'duplicate_date_tolerance' => 0,
        'is_default' => false,
    ]);

    $arr = $t->toTemplateArray();

    expect($arr['amount_mode'])->toBe('split');
    expect($arr['columns']['credit'])->toBe(2);
    expect($arr['columns']['debit'])->toBe(3);
    expect($arr['columns'])->not->toHaveKey('amount');
    expect($arr['columns']['description'])->toBe(1);
});

// --- Integration: BankTemplate model used in import ---

test('imports using BankTemplate model toTemplateArray', function () {
    $t = BankTemplate::create([
        'name' => 'Semicolon',
        'encoding' => 'UTF-8',
        'delimiter' => ';',
        'has_header' => true,
        'skip_rows' => 0,
        'date_format' => 'd/m/Y',
        'date_column' => 'Date',
        'amount_column' => 'Amount',
        'amount_mode' => 'single',
        'extra_columns' => [
            ['key' => 'description', 'column' => 'Description'],
            ['key' => 'reference', 'column' => 'Ref'],
        ],
        'duplicate_fields' => ['date', 'amount', 'description'],
        'duplicate_date_tolerance' => 0,
        'is_default' => true,
    ]);

    $csv = "Date;Description;Amount;Ref\n"
        ."01/05/2026;Deposit;5000;R001\n"
        ."02/05/2026;Withdrawal;-2000;R002\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $t->toTemplateArray());

    expect($result['imported'])->toBe(2);

    $txn = BankTransaction::where('reference', 'R001')->first();
    expect($txn->description)->toBe('Deposit');
    expect($txn->amount)->toBe('5000.00');
    expect($txn->transaction_date->format('Y-m-d'))->toBe('2026-05-01');
});

test('imports header-based column names correctly', function () {
    $template = defaultTemplate([
        'has_header' => true,
        'columns' => [
            'date' => 'Transaction Date',
            'amount' => 'Amount (USD)',
            'description' => 'Details',
        ],
    ]);

    $csv = "Transaction Date,Details,Amount (USD)\n"
        ."2026-05-01,Deposit,5000\n";

    $file = createCsvFile($csv);
    $result = $this->service->importCsv($file, template: $template);

    expect($result['imported'])->toBe(1);

    $txn = BankTransaction::first();
    expect($txn->description)->toBe('Deposit');
    expect($txn->amount)->toBe('5000.00');
});
