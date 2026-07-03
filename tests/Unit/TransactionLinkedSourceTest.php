<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Transaction;

test('transaction reports missing linked source when reference is null', function () {
    $transaction = new Transaction([
        'reference_type' => null,
        'reference_id' => null,
    ]);

    expect($transaction->hasLinkedReference())->toBeFalse()
        ->and($transaction->linkedSourceLabel())->toBe(__('No linked source'))
        ->and($transaction->linkedSourceDetail())->toBe(__('Manual entry or missing reference — not tied to a contribution, loan, deposit, or other source record.'));
});

test('transaction reports linked source label when reference is set', function () {
    $transaction = new Transaction([
        'reference_type' => Contribution::class,
        'reference_id' => 42,
    ]);

    expect($transaction->hasLinkedReference())->toBeTrue()
        ->and($transaction->linkedSourceLabel())->toBe('Contribution #42');
});
