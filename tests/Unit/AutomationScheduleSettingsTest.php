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

it('matches month-boundary jobs on the configured day at 00:30', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 00:30:00'));
    expect(AutomationScheduleSettings::isMonthBoundarySlot())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-07-06 00:31:00'));
    expect(AutomationScheduleSettings::isMonthBoundarySlot())->toBeFalse();

    Setting::set(AutomationScheduleSettings::GROUP, 'month_boundary_day', '10');
    Carbon::setTestNow(Carbon::parse('2026-07-10 00:30:00'));
    expect(AutomationScheduleSettings::monthBoundaryDay())->toBe(10)
        ->and(AutomationScheduleSettings::isMonthBoundarySlot())->toBeTrue();
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
