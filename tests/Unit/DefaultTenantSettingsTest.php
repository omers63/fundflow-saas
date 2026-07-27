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

it('persists every automation schedule default key for a fresh tenant', function () {
    DefaultTenantSettings::seed();

    $stored = Setting::getGroup(AutomationScheduleSettings::GROUP);

    foreach (array_keys(AutomationScheduleSettings::defaults()) as $key) {
        expect($stored)->toHaveKey($key);
    }

    expect($stored['contribution_apply_times'])->toBe('06:00')
        ->and($stored['loan_apply_times'])->toBe('06:00')
        ->and($stored['contribution_due_notify_days'])->toBe('0,7,14,21,28')
        ->and($stored['loan_due_notify_days'])->toBe('0,7,14,21,28')
        ->and($stored['master_invariants_time'])->toBe('06:00')
        ->and($stored['daily_reconcile_time'])->toBe('06:20')
        ->and($stored['nightly_reconcile_time'])->toBe('06:30')
        ->and($stored['bank_auto_match_time'])->toBe('08:00')
        ->and($stored['delinquency_digest_time'])->toBe('07:30')
        ->and($stored['master_invariants_frequency'])->toBe('daily')
        ->and($stored['master_invariants_times'])->toBe('06:00')
        ->and($stored['daily_reconcile_frequency'])->toBe('daily')
        ->and($stored['daily_reconcile_times'])->toBe('06:20')
        ->and($stored['nightly_reconcile_frequency'])->toBe('daily')
        ->and($stored['nightly_reconcile_times'])->toBe('06:30')
        ->and($stored['bank_auto_match_frequency'])->toBe('daily')
        ->and($stored['bank_auto_match_times'])->toBe('08:00')
        ->and($stored['delinquency_digest_frequency'])->toBe('daily')
        ->and($stored['delinquency_digest_times'])->toBe('07:30')
        ->and($stored['cycle_close_time'])->toBe('00:30')
        ->and($stored['cycle_init_time'])->toBe('00:35')
        ->and($stored['emi_close_time'])->toBe('00:45')
        ->and($stored['statements_time'])->toBe('00:30')
        ->and($stored['month_boundary_time'])->toBe('00:30')
        ->and($stored['late_fees_time'])->toBe('06:05')
        ->and($stored['loan_defaults_time'])->toBe('06:05')
        ->and($stored['onboarding_greeting_time'])->toBe('10:00')
        ->and($stored['dispatch_announcements_interval_minutes'])->toBe('1')
        ->and($stored['auto_accept_deposits'])->toBe('1')
        ->and($stored['auto_apply_collections'])->toBe('1')
        ->and($stored['late_fees_enabled'])->toBe('1')
        ->and($stored['loan_defaults_enabled'])->toBe('1')
        ->and($stored['dispatch_announcements_enabled'])->toBe('1')
        ->and($stored['onboarding_greeting_enabled'])->toBe('1')
        ->and($stored['notify_contribution_due'])->toBe('1')
        ->and($stored['notify_loan_due'])->toBe('1')
        ->and($stored['notify_delinquency_digest'])->toBe('1')
        ->and($stored['notify_reconciliation_digest'])->toBe('1')
        ->and($stored['notify_monthly_statements'])->toBe('1')
        ->and($stored['notify_announcements'])->toBe('1')
        ->and($stored['notify_onboarding_greeting'])->toBe('1');
});

it('backfills only missing automation schedule keys', function () {
    Setting::set(AutomationScheduleSettings::GROUP, 'master_invariants_time', '05:00');

    $written = AutomationScheduleSettings::seedDefaults(onlyMissing: true);

    expect($written)->toBeGreaterThan(0)
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'master_invariants_time'))->toBe('05:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'daily_reconcile_time'))->toBe('06:20')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'auto_apply_collections'))->toBe('1');
});

it('derives cadence times from legacy time when backfilling an existing tenant', function () {
    // Simulate an existing tenant with custom legacy times but no cadence keys.
    Setting::set(AutomationScheduleSettings::GROUP, 'nightly_reconcile_time', '05:00');
    Setting::set(AutomationScheduleSettings::GROUP, 'bank_auto_match_time', '09:30');

    AutomationScheduleSettings::seedDefaults(onlyMissing: true);

    // Cadence times should be derived from the custom legacy times.
    expect(Setting::get(AutomationScheduleSettings::GROUP, 'nightly_reconcile_times'))->toBe('05:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'bank_auto_match_times'))->toBe('09:30')
        // Legacy time should remain unchanged.
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'nightly_reconcile_time'))->toBe('05:00');
});

it('migration backfills all cadence keys for tenants missing them', function () {
    // Simulate a tenant that ran the pre-cadence migration: legacy times exist, cadence keys do not.
    foreach ([
        'master_invariants_time' => '06:00',
        'daily_reconcile_time' => '06:20',
        'nightly_reconcile_time' => '06:30',
        'bank_auto_match_time' => '08:00',
        'delinquency_digest_time' => '07:30',
    ] as $key => $value) {
        Setting::set(AutomationScheduleSettings::GROUP, $key, $value);
    }

    $migration = require base_path('database/migrations/tenant/2026_07_27_133608_ensure_automation_cadence_defaults_exist.php');
    $migration->up();

    $stored = Setting::getGroup(AutomationScheduleSettings::GROUP);

    foreach ([
        'master_invariants',
        'daily_reconcile',
        'nightly_reconcile',
        'bank_auto_match',
        'delinquency_digest',
    ] as $job) {
        expect($stored)->toHaveKey("{$job}_frequency")
            ->and($stored)->toHaveKey("{$job}_weekdays")
            ->and($stored)->toHaveKey("{$job}_month_days")
            ->and($stored)->toHaveKey("{$job}_times")
            ->and($stored["{$job}_frequency"])->toBe('daily')
            ->and($stored["{$job}_times"])->toBe($stored["{$job}_time"]);
    }
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
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'contribution_due_notify_days'))->toBe('0,7,14,21,28')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'loan_due_notify_days'))->toBe('0,7,14,21,28')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'auto_accept_deposits'))->toBe('1')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'auto_apply_collections'))->toBe('1')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'onboarding_greeting_enabled'))->toBe('1')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'master_invariants_time'))->toBe('06:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'daily_reconcile_time'))->toBe('06:20')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'bank_auto_match_time'))->toBe('08:00')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'month_boundary_time'))->toBe('00:30')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'notify_contribution_due'))->toBe('1')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'notify_reconciliation_digest'))->toBe('1')
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
