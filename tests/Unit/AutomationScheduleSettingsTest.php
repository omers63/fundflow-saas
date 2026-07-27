<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\AutomationScheduleSettings;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm(null);
    Setting::set('contribution', 'cycle_start_day', '6');
    AutomationScheduleSettings::saveFromForm([
        'automation_contribution_due_notify_days' => '0,7,14',
        'automation_contribution_due_notify_time' => '09:00',
        'automation_loan_due_notify_days' => '0,7,14',
        'automation_loan_due_notify_time' => '09:00',
        'automation_contribution_apply_times' => '06:00,18:00',
        'automation_loan_apply_times' => '06:00',
        'automation_month_boundary_day' => 6,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('matches contribution due notify slots on configured cycle offsets and time', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00'));

    expect(AutomationScheduleSettings::isContributionDueNotifySlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00'));
    expect(AutomationScheduleSettings::isContributionDueNotifySlot())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00:00'));
    expect(AutomationScheduleSettings::isContributionDueNotifySlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-07 09:00:00'));
    expect(AutomationScheduleSettings::isContributionDueNotifySlot())->toBeFalse();
});

it('matches apply slots once or twice daily while the cycle is open', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-10 06:00:00'));
    expect(AutomationScheduleSettings::isContributionApplySlot())->toBeTrue()
        ->and(AutomationScheduleSettings::isLoanApplySlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
    expect(AutomationScheduleSettings::isContributionApplySlot())->toBeTrue()
        ->and(AutomationScheduleSettings::isLoanApplySlot())->toBeFalse();
});

it('matches month-boundary jobs on the configured day and time', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 00:30:00'));
    expect(AutomationScheduleSettings::isMonthBoundarySlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-06 00:31:00'));
    expect(AutomationScheduleSettings::isMonthBoundarySlot())->toBeFalse();

    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_day', '10');
    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_time', '01:15');
    Carbon::setTestNow(Carbon::parse('2026-07-10 01:15:00'));
    expect(AutomationScheduleSettings::monthBoundaryDay())->toBe(10)
        ->and(AutomationScheduleSettings::monthBoundaryTime())->toBe('01:15')
        ->and(AutomationScheduleSettings::isMonthBoundarySlot())->toBeTrue();
});

it('matches daily automation slots at configured cadence times', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_master_invariants_frequency' => 'daily',
        'automation_master_invariants_times' => '06:05',
        'automation_daily_reconcile_frequency' => 'daily',
        'automation_daily_reconcile_times' => '06:25',
        'automation_nightly_reconcile_frequency' => 'daily',
        'automation_nightly_reconcile_times' => '06:35',
        'automation_bank_auto_match_frequency' => 'daily',
        'automation_bank_auto_match_times' => '08:10',
        'automation_delinquency_digest_frequency' => 'daily',
        'automation_delinquency_digest_times' => '07:40',
        'automation_emi_close_day' => 6,
        'automation_emi_close_time' => '00:50',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 06:05:00'));
    expect(AutomationScheduleSettings::isMasterInvariantsSlot())->toBeTrue()
        ->and(AutomationScheduleSettings::isDailyReconcileSlot())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-06 06:25:00'));
    expect(AutomationScheduleSettings::isDailyReconcileSlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-06 00:50:00'));
    expect(AutomationScheduleSettings::isEmiCloseSlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-07 00:50:00'));
    expect(AutomationScheduleSettings::isEmiCloseSlot())->toBeFalse();
});

it('nightly reconcile slot respects weekly cadence', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_nightly_reconcile_frequency' => 'weekly',
        'automation_nightly_reconcile_weekdays' => [1, 3], // Monday, Wednesday
        'automation_nightly_reconcile_times' => '06:30',
    ]);

    // Monday 2026-07-27 = isoWeekday 1
    Carbon::setTestNow(Carbon::parse('2026-07-27 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeTrue();

    // Tuesday 2026-07-28 = isoWeekday 2 (not configured)
    Carbon::setTestNow(Carbon::parse('2026-07-28 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeFalse();

    // Wednesday 2026-07-29 = isoWeekday 3
    Carbon::setTestNow(Carbon::parse('2026-07-29 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeTrue();
});

it('nightly reconcile slot respects monthly cadence', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_nightly_reconcile_frequency' => 'monthly',
        'automation_nightly_reconcile_month_days' => [6, 20],
        'automation_nightly_reconcile_times' => '06:30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-07-06 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-10 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-20 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeTrue();
});

it('nightly reconcile slot is disabled when frequency is disabled', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_nightly_reconcile_frequency' => 'disabled',
        'automation_nightly_reconcile_times' => '06:30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-07-27 06:30:00'));
    expect(AutomationScheduleSettings::isNightlyReconcileSlot())->toBeFalse();
});

it('nightly reconcile schedule label reflects cadence configuration', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_nightly_reconcile_frequency' => 'weekly',
        'automation_nightly_reconcile_weekdays' => [1, 5],
        'automation_nightly_reconcile_times' => '06:30',
    ]);

    $label = AutomationScheduleSettings::nightlyReconcileScheduleLabel();
    expect($label)->toContain('06:30')
        ->and($label)->not->toBe(__('Disabled'))
        ->and($label)->not->toStartWith(__('Daily at :times', ['times' => '06:30']));

    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_nightly_reconcile_frequency' => 'disabled',
        'automation_nightly_reconcile_times' => '06:30',
    ]);
    expect(AutomationScheduleSettings::nightlyReconcileScheduleLabel())->toBe(__('Disabled'));
});

it('falls back month-boundary day to cycle start day when unset', function () {
    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_day', null);
    Setting::set('contribution', 'cycle_start_day', '8');

    expect(AutomationScheduleSettings::monthBoundaryDay())->toBe(8);
});

it('defaults auto-accept deposits and auto-apply collections to on', function () {
    Setting::query()->where('group', AutomationScheduleSettings::GROUP)->delete();

    expect(AutomationScheduleSettings::autoAcceptDeposits())->toBeTrue()
        ->and(AutomationScheduleSettings::autoApplyCollections())->toBeTrue();
});

it('persists automation behaviour toggles from the settings form', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_auto_accept_deposits' => false,
        'automation_auto_apply_collections' => false,
    ]);

    expect(AutomationScheduleSettings::autoAcceptDeposits())->toBeFalse()
        ->and(AutomationScheduleSettings::autoApplyCollections())->toBeFalse()
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'auto_accept_deposits'))->toBe('0')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'auto_apply_collections'))->toBe('0');
});

it('persists automation notification toggles from the settings form', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_notify_contribution_due' => false,
        'automation_notify_loan_due' => false,
        'automation_notify_delinquency_digest' => false,
        'automation_notify_reconciliation_digest' => false,
        'automation_notify_monthly_statements' => false,
        'automation_notify_announcements' => false,
        'automation_notify_onboarding_greeting' => false,
    ]);

    expect(AutomationScheduleSettings::notifyContributionDue())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyLoanDue())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyDelinquencyDigest())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyReconciliationDigest())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyMonthlyStatements())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyAnnouncements())->toBeFalse()
        ->and(AutomationScheduleSettings::notifyOnboardingGreeting())->toBeFalse()
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'notify_contribution_due'))->toBe('0')
        ->and(Setting::get(AutomationScheduleSettings::GROUP, 'notify_announcements'))->toBe('0');
});

it('defaults automation notification toggles to on', function () {
    Setting::query()->where('group', AutomationScheduleSettings::GROUP)->delete();

    expect(AutomationScheduleSettings::notifyContributionDue())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyLoanDue())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyDelinquencyDigest())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyReconciliationDigest())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyMonthlyStatements())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyAnnouncements())->toBeTrue()
        ->and(AutomationScheduleSettings::notifyOnboardingGreeting())->toBeTrue()
        ->and(AutomationScheduleSettings::dispatchAnnouncementsEnabled())->toBeTrue()
        ->and(AutomationScheduleSettings::onboardingGreetingEnabled())->toBeTrue();
});

it('uses a dedicated statements schedule that can differ from month-boundary', function () {
    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_day', '6');
    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_time', '00:30');
    Setting::set(AutomationScheduleSettings::GROUP, 'statements_day', '8');
    Setting::set(AutomationScheduleSettings::GROUP, 'statements_time', '01:00');

    Carbon::setTestNow(Carbon::parse('2026-07-06 00:30:00'));
    expect(AutomationScheduleSettings::isMonthBoundarySlot())->toBeTrue()
        ->and(AutomationScheduleSettings::isStatementsSlot())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-08 01:00:00'));
    expect(AutomationScheduleSettings::isStatementsSlot())->toBeTrue()
        ->and(AutomationScheduleSettings::statementsScheduleLabel())->toContain('8')
        ->and(AutomationScheduleSettings::statementsScheduleLabel())->toContain('01:00');
});

it('gates announcement dispatch and late-fee slots with enable toggles', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_dispatch_announcements_enabled' => false,
        'automation_notify_announcements' => true,
        'automation_late_fees_enabled' => true,
        'automation_late_fees_time' => '06:05',
    ]);

    expect(AutomationScheduleSettings::shouldDispatchAnnouncements())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-07-10 06:05:00'));
    expect(AutomationScheduleSettings::isLateFeesSlot())->toBeTrue();

    Setting::set(AutomationScheduleSettings::GROUP, 'late_fees_enabled', '0');
    expect(AutomationScheduleSettings::isLateFeesSlot())->toBeFalse();
});

it('honours announcement dispatch polling interval', function () {
    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_dispatch_announcements_enabled' => true,
        'automation_notify_announcements' => true,
        'automation_dispatch_announcements_interval_minutes' => 5,
    ]);

    expect(AutomationScheduleSettings::dispatchAnnouncementsIntervalMinutes())->toBe(5)
        ->and(AutomationScheduleSettings::announcementsScheduleLabel())->toContain('5');

    $aligned = Carbon::createFromTimestampUTC(0)->addMinutes(100);
    Carbon::setTestNow($aligned);
    expect(AutomationScheduleSettings::isAnnouncementsDispatchSlot())->toBeTrue();

    Carbon::setTestNow($aligned->copy()->addMinute());
    expect(AutomationScheduleSettings::isAnnouncementsDispatchSlot())->toBeFalse();

    Carbon::setTestNow();

    AutomationScheduleSettings::saveFromForm([
        ...AutomationScheduleSettings::allForForm(),
        'automation_dispatch_announcements_interval_minutes' => 1,
    ]);
    expect(AutomationScheduleSettings::isAnnouncementsDispatchSlot())->toBeTrue();

    Setting::set(AutomationScheduleSettings::GROUP, 'dispatch_announcements_interval_minutes', '7');
    expect(AutomationScheduleSettings::dispatchAnnouncementsIntervalMinutes())->toBe(1);
});
