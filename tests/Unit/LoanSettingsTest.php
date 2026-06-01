<?php

use App\Models\Tenant\Setting;
use App\Support\LoanSettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', LoanSettings::GROUP)->delete();
});

it('uses defaults when loan settings are not stored', function () {
    expect(LoanSettings::eligibilityMonths())->toBe(12)
        ->and(LoanSettings::defaultInterestRate())->toBe(10.0)
        ->and(LoanSettings::guarantorTransferMissedThreshold())->toBe(3)
        ->and(Setting::loanGuarantorTransferMissedThreshold())->toBe(3);
});

it('falls back guarantor transfer threshold to grace cycles plus one', function () {
    LoanSettings::save(['default_grace_cycles' => 4]);

    expect(LoanSettings::guarantorTransferMissedThreshold())->toBe(5);
});

it('caps max loan amount by fund balance multiplier', function () {
    LoanSettings::save(['max_borrow_multiplier' => 2, 'max_loan_amount' => 0]);

    expect(LoanSettings::maxLoanAmountForMember(8000))->toBe(16000.0);
});

it('respects absolute max loan amount cap', function () {
    LoanSettings::save(['max_borrow_multiplier' => 3, 'max_loan_amount' => 10000]);

    expect(LoanSettings::maxLoanAmountForMember(8000))->toBe(10000.0);
});
