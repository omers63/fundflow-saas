<?php

use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    LoanSettings::save([
        'allow_funding_strategy_member_topup' => true,
        'allow_funding_strategy_split_percentage' => true,
        'allow_excess_fund_cash_out' => true,
    ]);
});

it('filters funding strategy options by tenant settings', function () {
    LoanSettings::save(['allow_funding_strategy_split_percentage' => false]);

    expect(LoanFundingStrategy::availableOptions())->toHaveKey(LoanFundingStrategy::MEMBER_FUND_TOPUP)
        ->and(LoanFundingStrategy::availableOptions())->not->toHaveKey(LoanFundingStrategy::SPLIT_PERCENTAGE)
        ->and(LoanFundingStrategy::defaultForApplication())->toBe(LoanFundingStrategy::MEMBER_FUND_TOPUP)
        ->and(LoanFundingStrategy::isAvailableForApplication(LoanFundingStrategy::SPLIT_PERCENTAGE))->toBeFalse();
});

it('hides cash-out disposition when tenant disables excess fund cash-out', function () {
    LoanSettings::save(['allow_excess_fund_cash_out' => false]);

    expect(LoanFundExcessDisposition::availableOptions())->toHaveKey(LoanFundExcessDisposition::KEEP_IN_FUND)
        ->and(LoanFundExcessDisposition::availableOptions())->not->toHaveKey(LoanFundExcessDisposition::CASH_OUT)
        ->and(LoanFundExcessDisposition::toCashOutFlag(LoanFundExcessDisposition::CASH_OUT))->toBeFalse();
});

it('maps excess fund disposition to cash-out flag', function () {
    expect(LoanFundExcessDisposition::toCashOutFlag(LoanFundExcessDisposition::KEEP_IN_FUND))->toBeFalse()
        ->and(LoanFundExcessDisposition::toCashOutFlag(LoanFundExcessDisposition::CASH_OUT))->toBeTrue()
        ->and(LoanFundExcessDisposition::fromCashOutFlag(true))->toBe(LoanFundExcessDisposition::CASH_OUT);
});
