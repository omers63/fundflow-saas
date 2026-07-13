<?php

declare(strict_types=1);

use App\Filament\Support\LoanInstallmentTableColumns;
use App\Models\Tenant\LoanInstallment;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
});

test('installment cycle label maps due date to labelled contribution cycle', function () {
    $octoberCycleInstallment = new LoanInstallment([
        'due_date' => Carbon::parse('2025-11-05'),
    ]);

    $septemberCycleInstallment = new LoanInstallment([
        'due_date' => Carbon::parse('2025-10-05'),
    ]);

    expect(LoanInstallmentTableColumns::cycleLabel($octoberCycleInstallment))->toBe('October 2025')
        ->and(LoanInstallmentTableColumns::cycleLabel($septemberCycleInstallment))->toBe('September 2025')
        ->and(LoanInstallmentTableColumns::cycleLabel(new LoanInstallment(['due_date' => null])))->toBeNull();
});
