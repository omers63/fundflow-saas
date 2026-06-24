<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\LoanFundingStrategy;
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
        ->and(LoanSettings::defaultInterestRate())->toBe(0.0)
        ->and(LoanSettings::guarantorTransferMissedThreshold())->toBe(3)
        ->and(LoanSettings::latePaymentConsecutiveThreshold())->toBe(3)
        ->and(LoanSettings::latePaymentRollingThreshold())->toBe(15)
        ->and(LoanSettings::latePaymentLookbackMonths())->toBe(60)
        ->and(LoanSettings::memberFundingSplitPercent())->toBe(50.0)
        ->and(LoanSettings::allowMemberFundTopupStrategy())->toBeTrue()
        ->and(LoanSettings::allowSplitPercentageStrategy())->toBeTrue()
        ->and(LoanSettings::allowExcessFundCashOut())->toBeTrue()
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

it('uses fund balance for member fund top-up strategy', function () {
    $portions = LoanSettings::resolveFundingPortions(10_000, 5_000, LoanFundingStrategy::MEMBER_FUND_TOPUP);

    expect($portions)->toBe([
        'member_portion' => 5000.0,
        'master_portion' => 5000.0,
    ]);
});

it('splits funding by configured member percentage when strategy is split', function () {
    LoanSettings::save(['member_funding_split_pct' => 40]);

    $portions = LoanSettings::resolveFundingPortions(10_000, 15_000, LoanFundingStrategy::SPLIT_PERCENTAGE);

    expect($portions)->toBe([
        'member_portion' => 4000.0,
        'master_portion' => 6000.0,
    ])
        ->and(LoanSettings::requiredMemberFundForLoanAmount(10_000, LoanFundingStrategy::SPLIT_PERCENTAGE))->toBe(4000.0)
        ->and(LoanSettings::masterFundingSplitPercent())->toBe(60.0);
});

it('treats legacy fifty fifty flag as fifty percent split setting', function () {
    Setting::set(LoanSettings::GROUP, 'fifty_fifty_funding_split', true);

    expect(LoanSettings::memberFundingSplitPercent())->toBe(50.0)
        ->and(LoanSettings::resolveFundingPortions(10_000, 20_000, LoanFundingStrategy::SPLIT_PERCENTAGE))->toBe([
            'member_portion' => 5000.0,
            'master_portion' => 5000.0,
        ]);
});

it('computes excess fund cash out only for split strategy', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    expect(LoanSettings::excessFundCashOutAmount(10_000, 12_000, LoanFundingStrategy::SPLIT_PERCENTAGE))->toBe(7000.0)
        ->and(LoanSettings::excessFundCashOutAmount(10_000, 12_000, LoanFundingStrategy::MEMBER_FUND_TOPUP))->toBe(0.0);
});

it('requires guarantor based on member share when split strategy needs more fund than balance', function () {
    LoanSettings::save([
        'member_funding_split_pct' => 50,
        'require_guarantor_above_fund_balance' => true,
    ]);

    $member = new Member;
    $member->setRelation('fundAccount', (object) ['balance' => 6000]);

    expect(LoanSettings::guarantorRequiredForAmount($member, 10_000, LoanFundingStrategy::SPLIT_PERCENTAGE))->toBeFalse()
        ->and(LoanSettings::guarantorRequiredForAmount($member, 20_000, LoanFundingStrategy::SPLIT_PERCENTAGE))->toBeTrue();
});
