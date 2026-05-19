<?php

use App\Models\Tenant\Setting;
use App\Support\CommunicationSettings;
use App\Support\ContributionPolicySettings;
use App\Support\StatementSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
});

test('setting can be stored and retrieved', function () {
    Setting::set('general', 'currency', 'USD');

    expect(Setting::get('general', 'currency'))->toBe('USD');
});

test('setting returns default when not found', function () {
    expect(Setting::get('general', 'missing_key', 'fallback'))->toBe('fallback');
});

test('setting can be updated', function () {
    Setting::set('general', 'currency', 'USD');
    Setting::set('general', 'currency', 'EUR');

    expect(Setting::get('general', 'currency'))->toBe('EUR');
    expect(Setting::where('group', 'general')->where('key', 'currency')->count())->toBe(1);
});

test('getGroup returns all settings for a group', function () {
    Setting::set('general', 'currency', 'USD');
    Setting::set('general', 'fund_name', 'Test Fund');
    Setting::set('other', 'unrelated', 'value');

    $group = Setting::getGroup('general');

    expect($group)->toHaveCount(2)
        ->and($group['currency'])->toBe('USD')
        ->and($group['fund_name'])->toBe('Test Fund');
});

test('contribution policy settings persist delinquency and late fees', function () {
    ContributionPolicySettings::saveFromForm([
        'delinquency_consecutive' => 2,
        'delinquency_total' => 10,
        'delinquency_lookback_months' => 48,
        'late_fee_contribution_1d' => 5,
        'late_fee_contribution_10d' => 10,
        'late_fee_contribution_20d' => 15,
        'late_fee_contribution_30d' => 20,
        'late_fee_repayment_1d' => 1,
        'late_fee_repayment_10d' => 2,
        'late_fee_repayment_20d' => 3,
        'late_fee_repayment_30d' => 4,
        'annual_subscription_fee' => 100,
    ]);

    expect(ContributionPolicySettings::consecutiveMissThreshold())->toBe(2)
        ->and(ContributionPolicySettings::totalMissThreshold())->toBe(10)
        ->and(ContributionPolicySettings::annualSubscriptionFee())->toBe(100.0)
        ->and((float) Setting::get('late_fee', 'contribution_day_30'))->toBe(20.0);
});

test('statement and communication settings persist from form state', function () {
    StatementSettings::saveFromForm([
        'statement_brand_name' => 'Acme Fund',
        'statement_tagline' => 'Monthly report',
        'statement_accent_color' => '#112233',
        'statement_footer_disclaimer' => 'Private.',
        'statement_signature_line' => 'Treasurer',
        'statement_auto_email' => true,
        'statement_include_transactions' => false,
        'statement_include_loan_section' => true,
        'statement_include_compliance' => false,
    ]);

    CommunicationSettings::saveFromForm([
        'communication_in_app_enabled' => false,
        'communication_email_enabled' => true,
    ]);

    expect(StatementSettings::brandName())->toBe('Acme Fund')
        ->and(StatementSettings::autoEmail())->toBeTrue()
        ->and(StatementSettings::includeTransactions())->toBeFalse()
        ->and(CommunicationSettings::inAppEnabled())->toBeFalse()
        ->and(CommunicationSettings::emailEnabled())->toBeTrue();
});
