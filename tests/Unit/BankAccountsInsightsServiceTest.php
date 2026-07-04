<?php

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Services\BankAccountsInsightsService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
});

it('builds six month statement trend from aggregated query', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');
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
        'transaction_date' => '2026-04-10',
        'description' => 'Recent import',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('trend-recent-import'),
    ]);
    $recent->forceFill(['created_at' => Carbon::parse('2026-04-10 10:00:00')])->saveQuietly();

    $old = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2025-10-10',
        'description' => 'Too old',
        'amount' => 100,
        'status' => 'imported',
        'hash' => md5('trend-old-import'),
    ]);
    $old->forceFill(['created_at' => Carbon::parse('2025-10-10 10:00:00')])->saveQuietly();

    $snapshot = app(BankAccountsInsightsService::class)->snapshot();
    $trend = collect($snapshot['trend']);
    $twoMonthsAgoLabel = Carbon::parse('2026-04-01')->locale(app()->getLocale())->translatedFormat('M');
    $twoMonthsAgo = $trend->firstWhere('label', $twoMonthsAgoLabel);

    expect($trend)->toHaveCount(6)
        ->and($twoMonthsAgo)->not->toBeNull()
        ->and((int) ($twoMonthsAgo['posted'] ?? 0))->toBe(1);

    BusinessDaySettings::saveFromForm(null);
});

it('includes treasury forecast in snapshot', function () {
    $snapshot = app(BankAccountsInsightsService::class)->snapshot();

    expect($snapshot['treasury_forecast'])->toHaveKeys([
        'pending_deposit_amount',
        'pending_cash_out_amount',
        'pending_net_amount',
        'projected_available_cash',
        'tone',
    ]);
});
