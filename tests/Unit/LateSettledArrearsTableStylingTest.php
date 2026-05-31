<?php

declare(strict_types=1);

use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Support\ContributionCollectionStatus;

test('contribution settled late when posted with is_late', function () {
    $contribution = new Contribution([
        'status' => 'posted',
        'is_late' => true,
    ]);

    expect(LateSettledArrearsTableStyling::contributionWasSettledLate($contribution))->toBeTrue()
        ->and(LateSettledArrearsTableStyling::contributionStatusColor($contribution))->toBe('danger')
        ->and(LateSettledArrearsTableStyling::contributionRecordClasses($contribution))->not->toBeNull();
});

test('contribution settled late when collected with is_late', function () {
    $contribution = new Contribution([
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'is_late' => true,
    ]);

    expect(LateSettledArrearsTableStyling::contributionWasSettledLate($contribution))->toBeTrue();
});

test('on-time posted contribution is not late settled', function () {
    $contribution = new Contribution([
        'status' => 'posted',
        'is_late' => false,
    ]);

    expect(LateSettledArrearsTableStyling::contributionWasSettledLate($contribution))->toBeFalse()
        ->and(LateSettledArrearsTableStyling::contributionStatusColor($contribution))->toBe('success')
        ->and(LateSettledArrearsTableStyling::contributionRecordClasses($contribution))->toBeNull();
});

test('paid installment with is_late is styled as late settled', function () {
    $installment = new LoanInstallment([
        'status' => 'paid',
        'is_late' => true,
    ]);

    expect(LateSettledArrearsTableStyling::installmentWasSettledLate($installment))->toBeTrue()
        ->and(LateSettledArrearsTableStyling::installmentStatusColor($installment))->toBe('danger')
        ->and(LateSettledArrearsTableStyling::installmentRecordClasses($installment))->not->toBeNull();
});

test('paid installment on time stays success styling', function () {
    $installment = new LoanInstallment([
        'status' => 'paid',
        'is_late' => false,
    ]);

    expect(LateSettledArrearsTableStyling::installmentWasSettledLate($installment))->toBeFalse()
        ->and(LateSettledArrearsTableStyling::installmentStatusColor($installment))->toBe('success');
});
