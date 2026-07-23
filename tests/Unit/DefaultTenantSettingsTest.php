<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\AutomationScheduleSettings;
use App\Support\CommunicationSettings;
use App\Support\ContributionPolicySettings;
use App\Support\DefaultTenantSettings;
use App\Support\FiscalSettings;
use App\Support\LedgerSettings;
use App\Support\LoanQueueProjectionSettings;
use App\Support\LoanSettings;
use App\Support\LocalizationSettings;
use App\Support\MemberNumberSettings;
use App\Support\PublicPageSettings;
use App\Support\PushEventSettings;
use App\Support\StatementSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
});

it('persists samman-shaped settings defaults for a fresh tenant', function () {
    DefaultTenantSettings::seed();

    expect(Setting::get('general', 'currency'))->toBe(DefaultTenantSettings::CURRENCY)
        ->and(Setting::get('general', 'fund_name'))->toBe('Samman Family Fund')
        ->and(Setting::get('contribution', 'cycle_start_day'))->toBe((string) DefaultTenantSettings::CYCLE_START_DAY)
        ->and(Setting::get(PublicPageSettings::GROUP, 'fund_name_en'))->toBe('Samman Family Fund')
        ->and(Setting::get(PublicPageSettings::GROUP, 'fee_new'))->toBe('150')
        ->and(Setting::get(PublicPageSettings::GROUP, 'membership_max_members'))->toBe('100')
        ->and(Setting::get(LoanSettings::GROUP, 'max_loan_amount'))->toBe('300000')
        ->and(Setting::get(LoanSettings::GROUP, 'settlement_threshold_pct'))->toBe('0.2')
        ->and(Setting::get(LoanSettings::GROUP, 'max_allowed_grace_cycles'))->toBe('1')
        ->and(Setting::get(LoanSettings::GROUP, 'allow_funding_strategy_member_topup'))->toBe('0')
        ->and(Setting::get(LoanSettings::GROUP, 'max_active_loans'))->toBe('1')
        ->and(Setting::get(MemberNumberSettings::GROUP, 'format'))->toBe(MemberNumberSettings::FORMAT_SEQUENTIAL)
        ->and(Setting::get(LocalizationSettings::GROUP, 'default_admin_locale'))->toBe('en')
        ->and(Setting::get(LocalizationSettings::GROUP, 'default_member_locale'))->toBe('ar')
        ->and(Setting::get('late_fee', 'contribution_day_10'))->toBe('0')
        ->and(Setting::get('subscription', 'annual_fee'))->toBe('0')
        ->and(Setting::get(StatementSettings::GROUP, 'auto_email'))->toBe('1')
        ->and(Setting::get(StatementSettings::GROUP, 'include_compliance'))->toBe('1')
        ->and(Setting::get('loan_queue_projection', 'include_contribution_arrears'))->toBe('1')
        ->and(Setting::get('reconciliation', 'digest_push_enabled'))->toBe('1')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'contribution_apply_times'))->toBe('06:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'loan_apply_times'))->toBe('06:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'contribution_due_notify_days'))->toBe('0,7,14,21')
        ->and(Setting::get(CommunicationSettings::GROUP, 'email_enabled'))->toBe('1')
        ->and(Setting::get(LedgerSettings::GROUP, 'show_manual_credit_debit'))->toBe('0')
        ->and(Setting::get(FiscalSettings::GROUP, 'fiscal_year_start_month'))->toBe('1')
        ->and(Setting::get(PushEventSettings::GROUP, 'contribution_due'))->toBe('1')
        ->and(Setting::get(PushEventSettings::GROUP, 'member_onboarding_greeting'))->toBe('1');
});

it('seeds every settings-tab policy group used by the admin Settings page', function () {
    DefaultTenantSettings::seed();

    $groups = Setting::query()->distinct()->orderBy('group')->pluck('group')->all();

    expect($groups)->toContain(
        'general',
        'contribution',
        'automation',
        'collection',
        'delinquency',
        'late_fee',
        'subscription',
        'loan',
        'loan_queue_projection',
        'localization',
        'ledger',
        'member_number',
        'fiscal',
        'public',
        'statement',
        'communication',
        'communication_brand',
        'notifications',
        'push_events',
        'reconciliation',
    );
});

it('does not seed frozen business day or twilio secrets', function () {
    DefaultTenantSettings::seed();

    expect(Setting::get('general', 'business_day'))->toBeNull()
        ->and(Setting::get('notifications', 'twilio_account_sid'))->toBe('')
        ->and(Setting::get('notifications', 'twilio_auth_token'))->toBe('')
        ->and(Setting::query()->where('group', 'like', 'legacy%')->exists())->toBeFalse();
});

it('aligns loan queue arrears default with samman policy', function () {
    expect(LoanQueueProjectionSettings::defaults()['include_contribution_arrears'])->toBeTrue()
        ->and(ContributionPolicySettings::collectionDefaults()['bank_match_manual_date_range_days'])->toBe(0);
});
