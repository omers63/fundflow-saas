<?php

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Services\BankAccountsInsightsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
});

it('builds six month statement trend from aggregated query', function () {
    request()->replace([]);

    $statement = BankStatement::create([
        'filename' => 'trend-bank.csv',
        'bank_name' => 'Trend Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
        'imported_at' => now(),
    ]);

    $recent = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Recent import',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('trend-recent-import'),
    ]);
    $recent->forceFill(['created_at' => Carbon::now()->subMonths(2)->startOfMonth()->addDay()])->saveQuietly();

    $old = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Too old',
        'amount' => 100,
        'status' => 'imported',
        'hash' => md5('trend-old-import'),
    ]);
    $old->forceFill(['created_at' => Carbon::now()->subMonths(8)])->saveQuietly();

    $snapshot = app(BankAccountsInsightsService::class)->snapshot();
    $trend = collect($snapshot['trend']);
    $twoMonthsAgoLabel = Carbon::now()->subMonths(2)->translatedFormat('M');
    $twoMonthsAgo = $trend->firstWhere('label', $twoMonthsAgoLabel);

    expect($trend)->toHaveCount(6)
        ->and($twoMonthsAgo)->not->toBeNull()
        ->and((int) $twoMonthsAgo['count'])->toBe(1);
});
