<?php

declare(strict_types=1);

use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationUploadDiagnostics;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('legacy migration upload diagnostics detects loan_id column', function () {
    Storage::disk('local')->put(LegacyMigrationWorkingCopy::LOANS_RELATIVE, implode("\n", [
        'loan_id,member_number,amount_approved,disbursed_at',
        '94,58,20000,2020-07-10',
    ]));

    $summary = app(LegacyMigrationUploadDiagnostics::class)->summarize();

    expect($summary['loans']['has_loan_id'] ?? false)->toBeTrue()
        ->and($summary['loans']['row_count'] ?? 0)->toBe(1);

    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::LOANS_RELATIVE);
});

test('legacy migration working copy overwrites loans file on snapshot', function () {
    $service = app(LegacyMigrationWorkingCopy::class);

    $first = storage_path('app/legacy-upload-test-first.csv');
    $second = storage_path('app/legacy-upload-test-second.csv');

    file_put_contents($first, "loan_status,member_number,amount_approved,disbursed_at\n,58,20000,2020-07-10\n");
    file_put_contents($second, "loan_id,loan_status,member_number,amount_approved,disbursed_at\n94,,58,20000,2020-07-10\n");

    $service->snapshot(['loans' => $first]);
    expect(in_array('loan_id', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::LOANS_RELATIVE)), true))->toBeFalse();

    $service->snapshot(['loans' => $second]);
    expect(in_array('loan_id', AssociativeCsv::headers(Storage::disk('local')->path(LegacyMigrationWorkingCopy::LOANS_RELATIVE)), true))->toBeTrue();

    @unlink($first);
    @unlink($second);
    Storage::disk('local')->delete(LegacyMigrationWorkingCopy::LOANS_RELATIVE);
});
