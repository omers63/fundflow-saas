<?php

declare(strict_types=1);

use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Support\ContributionCollectionStatus;
use App\Support\InstallmentCollectionStatus;

test('waived contribution uses info styling', function () {
    $contribution = new Contribution([
        'status' => 'waived',
        'is_late' => false,
    ]);

    expect(LateSettledArrearsTableStyling::contributionStatusColor($contribution))->toBe('info');
});

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

test('partially paid contribution uses warning styling and label', function () {
    $contribution = new Contribution([
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PARTIALLY_PENDING,
        'amount_collected' => 250,
        'is_late' => false,
    ]);

    expect(LateSettledArrearsTableStyling::contributionIsPartiallyPaid($contribution))->toBeTrue()
        ->and(LateSettledArrearsTableStyling::contributionStatusLabel($contribution))->toBe(__('Partially paid'))
        ->and(LateSettledArrearsTableStyling::contributionStatusColor($contribution))->toBe('warning');
});

test('partially paid installment uses warning styling and label', function () {
    $installment = new LoanInstallment([
        'status' => 'pending',
        'collection_status' => InstallmentCollectionStatus::PARTIALLY_PENDING,
        'amount_collected' => 250,
        'is_late' => false,
    ]);

    expect(LateSettledArrearsTableStyling::installmentIsPartiallyPaid($installment))->toBeTrue()
        ->and(LateSettledArrearsTableStyling::installmentStatusLabel($installment))->toBe(__('Partially paid'))
        ->and(LateSettledArrearsTableStyling::installmentStatusColor($installment))->toBe('warning');
});
