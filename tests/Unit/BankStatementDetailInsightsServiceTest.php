<?php

declare(strict_types=1);

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Services\BankStatementDetailInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
});

it('builds bank statement detail snapshot with sparkline and metrics', function (): void {
    $statement = BankStatement::create([
        'filename' => 'detail.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 3,
        'imported_rows' => 3,
        'duplicate_rows' => 0,
        'imported_at' => now(),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Imported credit',
        'amount' => 1000,
        'status' => 'imported',
        'hash' => md5('bs-detail-1'),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->subDay()->toDateString(),
        'description' => 'Posted debit',
        'amount' => -300,
        'status' => 'posted',
        'hash' => md5('bs-detail-2'),
    ]);

    $snapshot = app(BankStatementDetailInsightsService::class)->snapshot($statement);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'sparkline', 'recent', 'status_breakdown'])
        ->and($snapshot['sparkline'])->toHaveCount(7)
        ->and($snapshot['pending_post'])->toBe(1)
        ->and($snapshot['total_lines'])->toBe(2);
});
