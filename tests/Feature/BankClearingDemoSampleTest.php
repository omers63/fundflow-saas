<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use App\Services\BankImportService;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();

    foreach (['cash', 'fund', 'bank'] as $type) {
        Account::create(['type' => $type, 'name' => 'Master '.ucfirst($type), 'balance' => 50_000, 'is_master' => true]);
    }
});

test('bank clearing demo sample csv imports forty-two lines', function () {
    $path = base_path('storage/samples/bank-clearing-demo.csv');
    $file = new UploadedFile($path, 'bank-clearing-demo.csv', 'text/csv', null, true);

    $result = app(BankImportService::class)->importCsv($file, bankName: 'Demo Bank');

    expect($result['imported'])->toBe(42)
        ->and(BankTransaction::query()->whereHas('bankStatement', fn ($q) => $q->where('filename', 'bank-clearing-demo.csv'))->count())->toBe(42);
});

test('bank clearing demo seed command creates operational counterparts', function () {
    $this->artisan('bank-clearing:seed-demo', [
        '--tenants' => [tenant()->id],
        '--fresh' => true,
    ])->assertSuccessful();

    $matching = app(BankClearingMatchService::class);

    $allBank = BankTransaction::query()->count();
    $cashOuts = BankTransaction::query()->whereNotNull('cash_out_request_id')->count();
    $expenses = BankTransaction::query()->whereNotNull('expense_disbursement_id')->count();
    $deposits = BankTransaction::query()->whereNotNull('fund_posting_id')->count();

    $operational = $matching->applyPendingOperationalClearanceScope(BankTransaction::query())->get();

    expect($allBank)->toBe(19)
        ->and($deposits)->toBe(11)
        ->and($cashOuts)->toBe(7)
        ->and($expenses)->toBe(1)
        ->and($operational)->toHaveCount(19);

    $queue = app(BankClearingQueueService::class);

    $operationalAuto = $operational->firstWhere('reference', 'BC-AUTO-01');

    expect($operationalAuto)->not->toBeNull()
        ->and($matching->findUniqueCandidate($operationalAuto))->toBeNull();

    $path = base_path('storage/samples/bank-clearing-demo.csv');
    app(BankImportService::class)->importCsv(
        new UploadedFile($path, 'bank-clearing-demo.csv', 'text/csv', null, true),
        bankName: 'Demo Bank',
    );

    $importedAuto = BankTransaction::query()
        ->where('reference', 'BC-AUTO-01')
        ->whereHas('bankStatement', fn ($q) => $q->where('filename', 'bank-clearing-demo.csv'))
        ->first();

    expect($importedAuto)->not->toBeNull()
        ->and($matching->findUniqueCandidate($operationalAuto->fresh())?->is($importedAuto))->toBeTrue();

    $groupOperational = $operational->firstWhere('reference', 'BC-1M-01');
    $groupImports = BankTransaction::query()
        ->whereIn('reference', ['BC-1M-01A', 'BC-1M-01B', 'BC-1M-01C'])
        ->get();

    expect($groupOperational)->not->toBeNull()
        ->and($groupImports)->toHaveCount(3)
        ->and($matching->groupAmountsMatch(collect([$groupOperational]), $groupImports))->toBeTrue();

    $openImport = BankTransaction::query()->where('reference', 'BC-POST-01')->first();

    expect($openImport)->not->toBeNull()
        ->and($queue->isBankFileItem($openImport))->toBeTrue();
});
