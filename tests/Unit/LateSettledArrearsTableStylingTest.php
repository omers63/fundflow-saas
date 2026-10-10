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
        ->and(LateSettledArrearsTableStyling::contributionStatusLabel($contribution))->toBe(__('Paid (partial)'))
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
        ->and(LateSettledArrearsTableStyling::installmentStatusLabel($installment))->toBe(__('Paid (partial)'))
        ->and(LateSettledArrearsTableStyling::installmentStatusColor($installment))->toBe('warning');
});

test('guarantor-paid installment wins over late and has its own colour', function () {
    $installment = new LoanInstallment(['status' => 'paid', 'is_late' => true, 'paid_by_guarantor' => true]);

    expect(LateSettledArrearsTableStyling::installmentStatusLabel($installment))->toBe(__('Paid (guarantor)'))
        ->and(LateSettledArrearsTableStyling::installmentStatusColor($installment))->toBe('violet');
});

test('waived installment with the EMI collected is paid early, otherwise waived', function () {
    $skipped = new LoanInstallment(['status' => 'waived', 'amount_collected' => 500]);
    $explicit = new LoanInstallment(['status' => 'waived', 'waive_reason' => 'early_skip', 'amount_collected' => 500]);
    $threshold = new LoanInstallment(['status' => 'waived', 'waive_reason' => 'threshold', 'amount_collected' => 4]);
    $plain = new LoanInstallment(['status' => 'waived', 'amount_collected' => 0]);

    expect(LateSettledArrearsTableStyling::installmentStatusLabel($skipped))->toBe(__('Paid (early)'))
        ->and(LateSettledArrearsTableStyling::installmentStatusLabel($explicit))->toBe(__('Paid (early)'))
        ->and(LateSettledArrearsTableStyling::installmentStatusLabel($threshold))->toBe(__('Waived'))
        ->and(LateSettledArrearsTableStyling::installmentStatusLabel($plain))->toBe(__('Waived'));
});

test('waived contribution shows its reason as the hint and is never paid early', function () {
    $contribution = new Contribution(['status' => 'waived', 'waive_reason' => 'cutoff', 'amount_collected' => 50]);
    $state = LateSettledArrearsTableStyling::contributionPaymentState($contribution);

    expect($state['code'])->toBe('waived')
        ->and($state['label'])->toBe(__('Waived'))
        ->and($state['hint'])->toBe(__('Before arrears cutoff; not collected'));
});

test('posted contribution is Paid and overdue pending contribution is Overdue', function () {
    $posted = new Contribution(['status' => 'posted', 'is_late' => false]);
    $overdue = new Contribution(['status' => 'pending', 'collection_status' => 'overdue']);

    expect(LateSettledArrearsTableStyling::contributionStatusLabel($posted))->toBe(__('Paid'))
        ->and(LateSettledArrearsTableStyling::contributionStatusLabel($overdue))->toBe(__('Overdue'))
        ->and(LateSettledArrearsTableStyling::contributionStatusColor($overdue))->toBe('danger');
});

test('early settlement chips: full, partial and skipped cycles', function () {
    $full = new LoanInstallment(['status' => 'paid', 'settled_via' => 'early_full']);
    $partial = new LoanInstallment(['status' => 'paid', 'settled_via' => 'early_partial']);
    $skipped = new LoanInstallment(['status' => 'waived', 'waive_reason' => 'early_skip', 'amount_collected' => 500]);
    $normal = new LoanInstallment(['status' => 'pending']);

    expect(array_column(LateSettledArrearsTableStyling::installmentFlags($full), 'label'))->toBe([__('Early settlement (full)')])
        ->and(array_column(LateSettledArrearsTableStyling::installmentFlags($partial), 'label'))->toBe([__('Early settlement (partial)')])
        ->and(array_column(LateSettledArrearsTableStyling::installmentFlags($skipped), 'label'))->toBe([__('Early settlement (partial)')])
        ->and(LateSettledArrearsTableStyling::installmentFlags($normal))->toBe([]);
});

test('installment collected above its scheduled amount is not flagged above limit', function () {
    $over = new LoanInstallment(['status' => 'paid', 'amount' => 500, 'amount_collected' => 650]);

    expect(LateSettledArrearsTableStyling::installmentFlags($over))->toBe([]);
});

test('an EMI paid in a cycle before its own cycle gets the partial early-settlement flag', function () {
    $make = fn (string $due, string $paid) => new LoanInstallment([
        'status' => 'paid', 'due_date' => $due, 'paid_at' => $paid, 'amount' => 500, 'amount_collected' => 500,
    ]);

    expect(array_column(LateSettledArrearsTableStyling::installmentFlags($make('2024-08-05', '2024-06-03 09:00:00')), 'code'))->toBe(['early_settlement_partial'])
        ->and(LateSettledArrearsTableStyling::installmentFlags($make('2024-07-05', '2024-06-10 09:00:00')))->toBe([])
        ->and(LateSettledArrearsTableStyling::installmentFlags($make('2024-07-05', '2024-07-20 09:00:00')))->toBe([]);
});
