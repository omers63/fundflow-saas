<?php

declare(strict_types=1);

use App\Services\DatabaseMaintenanceService;
use App\Support\TenantSchemaNotes;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

it('catalogs notable tenant migrations that exist on disk', function () {
    $catalog = TenantSchemaNotes::catalog();

    expect($catalog)->not->toBeEmpty()
        ->and(count($catalog))->toBeGreaterThan(20);

    foreach ($catalog as $note) {
        expect($note)->toHaveKeys(['migration', 'title', 'body'])
            ->and(database_path('migrations/tenant/' . $note['migration'] . '.php'))
            ->toBeFile();
    }

    $migrations = collect($catalog)->pluck('migration');

    expect($migrations->unique()->count())->toBe($migrations->count())
        ->and($migrations->first())->toStartWith('2026_07_24')
        ->and($migrations)->toContain('2026_07_24_181432_add_admin_transfer_fields_to_loans_table')
        ->and($migrations)->toContain('2026_07_24_194016_create_member_cash_transfer_requests_table')
        ->and($migrations)->toContain('2026_05_31_092636_create_cash_out_requests_table');
});

it('marks schema notes with applied status from the tenant migrations table', function () {
    $notes = app(DatabaseMaintenanceService::class)->recentSchemaNotes();

    expect($notes)->not->toBeEmpty();

    foreach ($notes as $note) {
        expect($note)->toHaveKey('applied')
            ->and($note['applied'])->toBeBool();
    }

    expect(collect($notes)->firstWhere(
        'migration',
        '2026_07_24_181432_add_admin_transfer_fields_to_loans_table',
    ))->not->toBeNull()
        ->and(collect($notes)->first()['title'])->not->toBeEmpty();
});
