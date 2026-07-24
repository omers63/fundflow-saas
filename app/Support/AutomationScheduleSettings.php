<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use Carbon\Carbon;

/**
 * Tenant-configurable cron slots for Automation (notify / apply / daily / month-boundary jobs).
 */
final class AutomationScheduleSettings
{
    public const GROUP = 'automation';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // Days after cycle open (0 = open day) when due notifications fire.
            'contribution_due_notify_days' => '0,7,14,21',
            'contribution_due_notify_time' => '09:00',
            'loan_due_notify_days' => '0,7,14,21',
            'loan_due_notify_time' => '09:00',
            // One or two HH:MM times (comma-separated) while the cycle is open.
            'contribution_apply_times' => '06:00',
            'loan_apply_times' => '06:00',
            // Day of month for monthly reconcile + statements (null → cycle start day).
            'month_boundary_day' => null,
            'month_boundary_time' => '00:30',
            // Cycle transition (close previous / init next) times on cycle start day.
            'cycle_close_time' => '00:30',
            'cycle_init_time' => '00:35',
            // EMI window close (null day → cycle start day).
            'emi_close_day' => null,
            'emi_close_time' => '00:45',
            // Daily fund / bank / delinquency jobs.
            'master_invariants_time' => '06:00',
            'daily_reconcile_time' => '06:20',
            'nightly_reconcile_time' => '06:30',
            'bank_auto_match_time' => '08:00',
            'delinquency_digest_time' => '07:30',
            // Statements (defaults follow month-boundary day/time when unset).
            'statements_day' => null,
            'statements_time' => '00:30',
            // Messaging / chained maintenance.
            'dispatch_announcements_enabled' => true,
            'dispatch_announcements_interval_minutes' => 1,
            'onboarding_greeting_enabled' => false,
            'onboarding_greeting_time' => '10:00',
            'late_fees_enabled' => true,
            'late_fees_time' => '06:05',
            'loan_defaults_enabled' => true,
            'loan_defaults_time' => '06:05',
            // Whether scheduled automation may send notifications (jobs still run when applicable).
            'notify_contribution_due' => true,
            'notify_loan_due' => true,
            'notify_delinquency_digest' => true,
            'notify_reconciliation_digest' => true,
            'notify_monthly_statements' => true,
            'notify_announcements' => true,
            'notify_onboarding_greeting' => true,
            // Behaviour toggles (Samman default: automate deposits + cash allocation).
            'auto_accept_deposits' => true,
            'auto_apply_collections' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $all = array_merge(self::defaults(), Setting::getGroup(self::GROUP));

        return [
            'automation_auto_accept_deposits' => self::boolFromStored($all['auto_accept_deposits'] ?? null, true),
            'automation_auto_apply_collections' => self::boolFromStored($all['auto_apply_collections'] ?? null, true),
            'automation_contribution_due_notify_days' => (string) ($all['contribution_due_notify_days'] ?? self::defaults()['contribution_due_notify_days']),
            'automation_contribution_due_notify_time' => (string) ($all['contribution_due_notify_time'] ?? self::defaults()['contribution_due_notify_time']),
            'automation_loan_due_notify_days' => (string) ($all['loan_due_notify_days'] ?? self::defaults()['loan_due_notify_days']),
            'automation_loan_due_notify_time' => (string) ($all['loan_due_notify_time'] ?? self::defaults()['loan_due_notify_time']),
            'automation_contribution_apply_times' => (string) ($all['contribution_apply_times'] ?? self::defaults()['contribution_apply_times']),
            'automation_loan_apply_times' => (string) ($all['loan_apply_times'] ?? self::defaults()['loan_apply_times']),
            'automation_month_boundary_day' => self::monthBoundaryDay(),
            'automation_month_boundary_time' => self::monthBoundaryTime(),
            'automation_cycle_close_time' => self::cycleCloseTime(),
            'automation_cycle_init_time' => self::cycleInitTime(),
            'automation_emi_close_day' => self::emiCloseDay(),
            'automation_emi_close_time' => self::emiCloseTime(),
            'automation_master_invariants_time' => self::masterInvariantsTime(),
            'automation_daily_reconcile_time' => self::dailyReconcileTime(),
            'automation_nightly_reconcile_time' => self::nightlyReconcileTime(),
            'automation_bank_auto_match_time' => self::bankAutoMatchTime(),
            'automation_delinquency_digest_time' => self::delinquencyDigestTime(),
            'automation_statements_day' => self::statementsDay(),
            'automation_statements_time' => self::statementsTime(),
            'automation_dispatch_announcements_enabled' => self::dispatchAnnouncementsEnabled(),
            'automation_dispatch_announcements_interval_minutes' => self::dispatchAnnouncementsIntervalMinutes(),
            'automation_onboarding_greeting_enabled' => self::onboardingGreetingEnabled(),
            'automation_onboarding_greeting_time' => self::onboardingGreetingTime(),
            'automation_late_fees_enabled' => self::lateFeesEnabled(),
            'automation_late_fees_time' => self::lateFeesTime(),
            'automation_loan_defaults_enabled' => self::loanDefaultsEnabled(),
            'automation_loan_defaults_time' => self::loanDefaultsTime(),
            'automation_notify_contribution_due' => self::notifyContributionDue(),
            'automation_notify_loan_due' => self::notifyLoanDue(),
            'automation_notify_delinquency_digest' => self::notifyDelinquencyDigest(),
            'automation_notify_reconciliation_digest' => self::notifyReconciliationDigest(),
            'automation_notify_monthly_statements' => self::notifyMonthlyStatements(),
            'automation_notify_announcements' => self::notifyAnnouncements(),
            'automation_notify_onboarding_greeting' => self::notifyOnboardingGreeting(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(self::GROUP, 'auto_accept_deposits', ($state['automation_auto_accept_deposits'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'auto_apply_collections', ($state['automation_auto_apply_collections'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'contribution_due_notify_days', self::normalizeDayList($state['automation_contribution_due_notify_days'] ?? null));
        Setting::set(self::GROUP, 'contribution_due_notify_time', self::normalizeClockTime($state['automation_contribution_due_notify_time'] ?? null) ?? '09:00');
        Setting::set(self::GROUP, 'loan_due_notify_days', self::normalizeDayList($state['automation_loan_due_notify_days'] ?? null));
        Setting::set(self::GROUP, 'loan_due_notify_time', self::normalizeClockTime($state['automation_loan_due_notify_time'] ?? null) ?? '09:00');
        Setting::set(self::GROUP, 'contribution_apply_times', self::normalizeTimesList($state['automation_contribution_apply_times'] ?? null, max: 2));
        Setting::set(self::GROUP, 'loan_apply_times', self::normalizeTimesList($state['automation_loan_apply_times'] ?? null, max: 2));

        $boundary = isset($state['automation_month_boundary_day']) && $state['automation_month_boundary_day'] !== ''
            ? max(1, min(28, (int) $state['automation_month_boundary_day']))
            : null;
        Setting::set(self::GROUP, 'month_boundary_day', $boundary !== null ? (string) $boundary : null);
        Setting::set(self::GROUP, 'month_boundary_time', self::normalizeClockTime($state['automation_month_boundary_time'] ?? null) ?? '00:30');
        Setting::set(self::GROUP, 'cycle_close_time', self::normalizeClockTime($state['automation_cycle_close_time'] ?? null) ?? '00:30');
        Setting::set(self::GROUP, 'cycle_init_time', self::normalizeClockTime($state['automation_cycle_init_time'] ?? null) ?? '00:35');

        $emiDay = isset($state['automation_emi_close_day']) && $state['automation_emi_close_day'] !== ''
            ? max(1, min(28, (int) $state['automation_emi_close_day']))
            : null;
        Setting::set(self::GROUP, 'emi_close_day', $emiDay !== null ? (string) $emiDay : null);
        Setting::set(self::GROUP, 'emi_close_time', self::normalizeClockTime($state['automation_emi_close_time'] ?? null) ?? '00:45');

        Setting::set(self::GROUP, 'master_invariants_time', self::normalizeClockTime($state['automation_master_invariants_time'] ?? null) ?? '06:00');
        Setting::set(self::GROUP, 'daily_reconcile_time', self::normalizeClockTime($state['automation_daily_reconcile_time'] ?? null) ?? '06:20');
        Setting::set(self::GROUP, 'nightly_reconcile_time', self::normalizeClockTime($state['automation_nightly_reconcile_time'] ?? null) ?? '06:30');
        Setting::set(self::GROUP, 'bank_auto_match_time', self::normalizeClockTime($state['automation_bank_auto_match_time'] ?? null) ?? '08:00');
        Setting::set(self::GROUP, 'delinquency_digest_time', self::normalizeClockTime($state['automation_delinquency_digest_time'] ?? null) ?? '07:30');

        $statementsDay = isset($state['automation_statements_day']) && $state['automation_statements_day'] !== ''
            ? max(1, min(28, (int) $state['automation_statements_day']))
            : null;
        Setting::set(self::GROUP, 'statements_day', $statementsDay !== null ? (string) $statementsDay : null);
        Setting::set(self::GROUP, 'statements_time', self::normalizeClockTime($state['automation_statements_time'] ?? null) ?? '00:30');

        Setting::set(self::GROUP, 'dispatch_announcements_enabled', ($state['automation_dispatch_announcements_enabled'] ?? true) ? '1' : '0');
        Setting::set(
            self::GROUP,
            'dispatch_announcements_interval_minutes',
            (string) self::normalizeIntervalMinutes($state['automation_dispatch_announcements_interval_minutes'] ?? null),
        );
        Setting::set(self::GROUP, 'onboarding_greeting_enabled', ($state['automation_onboarding_greeting_enabled'] ?? false) ? '1' : '0');
        Setting::set(self::GROUP, 'onboarding_greeting_time', self::normalizeClockTime($state['automation_onboarding_greeting_time'] ?? null) ?? '10:00');
        Setting::set(self::GROUP, 'late_fees_enabled', ($state['automation_late_fees_enabled'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'late_fees_time', self::normalizeClockTime($state['automation_late_fees_time'] ?? null) ?? '06:05');
        Setting::set(self::GROUP, 'loan_defaults_enabled', ($state['automation_loan_defaults_enabled'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'loan_defaults_time', self::normalizeClockTime($state['automation_loan_defaults_time'] ?? null) ?? '06:05');

        Setting::set(self::GROUP, 'notify_contribution_due', ($state['automation_notify_contribution_due'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_loan_due', ($state['automation_notify_loan_due'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_delinquency_digest', ($state['automation_notify_delinquency_digest'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_reconciliation_digest', ($state['automation_notify_reconciliation_digest'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_monthly_statements', ($state['automation_notify_monthly_statements'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_announcements', ($state['automation_notify_announcements'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_onboarding_greeting', ($state['automation_notify_onboarding_greeting'] ?? true) ? '1' : '0');
    }

    public static function autoAcceptDeposits(): bool
    {
        return self::boolFromStored(self::get('auto_accept_deposits', null), (bool) self::defaults()['auto_accept_deposits']);
    }

    /**
     * When enabled, scheduled apply jobs and realtime cash settlement allocate
     * parent→dependent shares, contributions, and EMI repayments automatically.
     */
    public static function autoApplyCollections(): bool
    {
        return self::boolFromStored(self::get('auto_apply_collections', null), (bool) self::defaults()['auto_apply_collections']);
    }

    /**
     * @return list<int>
     */
    public static function contributionDueNotifyDays(): array
    {
        return self::parseDayList(self::get('contribution_due_notify_days', self::defaults()['contribution_due_notify_days']));
    }

    public static function contributionDueNotifyTime(): string
    {
        return self::normalizeClockTime(self::get('contribution_due_notify_time', '09:00')) ?? '09:00';
    }

    /**
     * @return list<int>
     */
    public static function loanDueNotifyDays(): array
    {
        return self::parseDayList(self::get('loan_due_notify_days', self::defaults()['loan_due_notify_days']));
    }

    public static function loanDueNotifyTime(): string
    {
        return self::normalizeClockTime(self::get('loan_due_notify_time', '09:00')) ?? '09:00';
    }

    /**
     * @return list<string>
     */
    public static function contributionApplyTimes(): array
    {
        return self::parseTimesList(self::get('contribution_apply_times', self::defaults()['contribution_apply_times']), max: 2);
    }

    /**
     * @return list<string>
     */
    public static function loanApplyTimes(): array
    {
        return self::parseTimesList(self::get('loan_apply_times', self::defaults()['loan_apply_times']), max: 2);
    }

    public static function monthBoundaryDay(): int
    {
        return self::resolveCalendarDay('month_boundary_day');
    }

    public static function monthBoundaryTime(): string
    {
        return self::normalizeClockTime(self::get('month_boundary_time', '00:30')) ?? '00:30';
    }

    public static function cycleCloseTime(): string
    {
        return self::normalizeClockTime(self::get('cycle_close_time', '00:30')) ?? '00:30';
    }

    public static function cycleInitTime(): string
    {
        return self::normalizeClockTime(self::get('cycle_init_time', '00:35')) ?? '00:35';
    }

    public static function emiCloseDay(): int
    {
        return self::resolveCalendarDay('emi_close_day');
    }

    public static function emiCloseTime(): string
    {
        return self::normalizeClockTime(self::get('emi_close_time', '00:45')) ?? '00:45';
    }

    public static function masterInvariantsTime(): string
    {
        return self::normalizeClockTime(self::get('master_invariants_time', '06:00')) ?? '06:00';
    }

    public static function dailyReconcileTime(): string
    {
        return self::normalizeClockTime(self::get('daily_reconcile_time', '06:20')) ?? '06:20';
    }

    public static function nightlyReconcileTime(): string
    {
        return self::normalizeClockTime(self::get('nightly_reconcile_time', '06:30')) ?? '06:30';
    }

    public static function bankAutoMatchTime(): string
    {
        return self::normalizeClockTime(self::get('bank_auto_match_time', '08:00')) ?? '08:00';
    }

    public static function delinquencyDigestTime(): string
    {
        return self::normalizeClockTime(self::get('delinquency_digest_time', '07:30')) ?? '07:30';
    }

    public static function notifyContributionDue(): bool
    {
        return self::boolFromStored(self::get('notify_contribution_due', null), (bool) self::defaults()['notify_contribution_due']);
    }

    public static function notifyLoanDue(): bool
    {
        return self::boolFromStored(self::get('notify_loan_due', null), (bool) self::defaults()['notify_loan_due']);
    }

    public static function notifyDelinquencyDigest(): bool
    {
        return self::boolFromStored(self::get('notify_delinquency_digest', null), (bool) self::defaults()['notify_delinquency_digest']);
    }

    public static function notifyReconciliationDigest(): bool
    {
        return self::boolFromStored(self::get('notify_reconciliation_digest', null), (bool) self::defaults()['notify_reconciliation_digest']);
    }

    public static function notifyMonthlyStatements(): bool
    {
        return self::boolFromStored(self::get('notify_monthly_statements', null), (bool) self::defaults()['notify_monthly_statements']);
    }

    public static function notifyAnnouncements(): bool
    {
        return self::boolFromStored(self::get('notify_announcements', null), (bool) self::defaults()['notify_announcements']);
    }

    public static function notifyOnboardingGreeting(): bool
    {
        return self::boolFromStored(self::get('notify_onboarding_greeting', null), (bool) self::defaults()['notify_onboarding_greeting']);
    }

    public static function statementsDay(): int
    {
        $stored = self::get('statements_day', null);

        if ($stored !== null && $stored !== '') {
            return max(1, min(28, (int) $stored));
        }

        return self::monthBoundaryDay();
    }

    public static function statementsTime(): string
    {
        return self::normalizeClockTime(self::get('statements_time', '00:30')) ?? '00:30';
    }

    public static function dispatchAnnouncementsEnabled(): bool
    {
        return self::boolFromStored(self::get('dispatch_announcements_enabled', null), (bool) self::defaults()['dispatch_announcements_enabled']);
    }

    public static function dispatchAnnouncementsIntervalMinutes(): int
    {
        return self::normalizeIntervalMinutes(self::get(
            'dispatch_announcements_interval_minutes',
            self::defaults()['dispatch_announcements_interval_minutes'],
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function pollingIntervalOptions(): array
    {
        return [
            1 => __('Every minute'),
            2 => __('Every 2 minutes'),
            5 => __('Every 5 minutes'),
            10 => __('Every 10 minutes'),
            15 => __('Every 15 minutes'),
            30 => __('Every 30 minutes'),
            60 => __('Every hour'),
        ];
    }

    public static function onboardingGreetingEnabled(): bool
    {
        return self::boolFromStored(self::get('onboarding_greeting_enabled', null), (bool) self::defaults()['onboarding_greeting_enabled']);
    }

    public static function onboardingGreetingTime(): string
    {
        return self::normalizeClockTime(self::get('onboarding_greeting_time', '10:00')) ?? '10:00';
    }

    public static function lateFeesEnabled(): bool
    {
        return self::boolFromStored(self::get('late_fees_enabled', null), (bool) self::defaults()['late_fees_enabled']);
    }

    public static function lateFeesTime(): string
    {
        return self::normalizeClockTime(self::get('late_fees_time', '06:05')) ?? '06:05';
    }

    public static function loanDefaultsEnabled(): bool
    {
        return self::boolFromStored(self::get('loan_defaults_enabled', null), (bool) self::defaults()['loan_defaults_enabled']);
    }

    public static function loanDefaultsTime(): string
    {
        return self::normalizeClockTime(self::get('loan_defaults_time', '06:05')) ?? '06:05';
    }

    public static function isContributionDueNotifySlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isDueNotifySlot(
            self::contributionDueNotifyDays(),
            self::contributionDueNotifyTime(),
            $businessDay,
            $wallClock,
        );
    }

    public static function isLoanDueNotifySlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isDueNotifySlot(
            self::loanDueNotifyDays(),
            self::loanDueNotifyTime(),
            $businessDay,
            $wallClock,
        );
    }

    public static function isContributionApplySlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isApplySlot(self::contributionApplyTimes(), $businessDay, $wallClock);
    }

    public static function isLoanApplySlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isApplySlot(self::loanApplyTimes(), $businessDay, $wallClock);
    }

    public static function isMonthBoundarySlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isCalendarDayTimeSlot(self::monthBoundaryDay(), self::monthBoundaryTime(), $businessDay, $wallClock);
    }

    public static function isCycleCloseSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::cycleCloseTime(), $wallClock);
    }

    public static function isCycleInitSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::cycleInitTime(), $wallClock);
    }

    public static function isEmiCloseSlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isCalendarDayTimeSlot(self::emiCloseDay(), self::emiCloseTime(), $businessDay, $wallClock);
    }

    public static function isMasterInvariantsSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::masterInvariantsTime(), $wallClock);
    }

    public static function isDailyReconcileSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::dailyReconcileTime(), $wallClock);
    }

    public static function isNightlyReconcileSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::nightlyReconcileTime(), $wallClock);
    }

    public static function isBankAutoMatchSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::bankAutoMatchTime(), $wallClock);
    }

    public static function isDelinquencyDigestSlot(?Carbon $wallClock = null): bool
    {
        return self::isWallClockSlot(self::delinquencyDigestTime(), $wallClock);
    }

    public static function isStatementsSlot(?Carbon $businessDay = null, ?Carbon $wallClock = null): bool
    {
        return self::isCalendarDayTimeSlot(self::statementsDay(), self::statementsTime(), $businessDay, $wallClock);
    }

    public static function isOnboardingGreetingSlot(?Carbon $wallClock = null): bool
    {
        return self::onboardingGreetingEnabled()
            && self::isWallClockSlot(self::onboardingGreetingTime(), $wallClock);
    }

    public static function isLateFeesSlot(?Carbon $wallClock = null): bool
    {
        return self::lateFeesEnabled()
            && self::isWallClockSlot(self::lateFeesTime(), $wallClock);
    }

    public static function isLoanDefaultsSlot(?Carbon $wallClock = null): bool
    {
        return self::loanDefaultsEnabled()
            && self::isWallClockSlot(self::loanDefaultsTime(), $wallClock);
    }

    public static function shouldDispatchAnnouncements(): bool
    {
        return self::dispatchAnnouncementsEnabled() && self::notifyAnnouncements();
    }

    public static function isAnnouncementsDispatchSlot(?Carbon $wallClock = null): bool
    {
        if (! self::shouldDispatchAnnouncements()) {
            return false;
        }

        return self::isPollingIntervalSlot(self::dispatchAnnouncementsIntervalMinutes(), $wallClock);
    }

    public static function contributionDueNotifyScheduleLabel(): string
    {
        return __('On cycle days :days at :time', [
            'days' => implode(', ', self::contributionDueNotifyDays()),
            'time' => self::contributionDueNotifyTime(),
        ]);
    }

    public static function loanDueNotifyScheduleLabel(): string
    {
        return __('On cycle days :days at :time', [
            'days' => implode(', ', self::loanDueNotifyDays()),
            'time' => self::loanDueNotifyTime(),
        ]);
    }

    public static function contributionApplyScheduleLabel(): string
    {
        return __('Daily while cycle open at :times (then late fees)', [
            'times' => implode(', ', self::contributionApplyTimes()),
        ]);
    }

    public static function loanApplyScheduleLabel(): string
    {
        return __('Daily while cycle open at :times (then delinquency check)', [
            'times' => implode(', ', self::loanApplyTimes()),
        ]);
    }

    public static function monthBoundaryScheduleLabel(): string
    {
        return __('On day :day of each month at :time', [
            'day' => self::monthBoundaryDay(),
            'time' => self::monthBoundaryTime(),
        ]);
    }

    public static function masterInvariantsScheduleLabel(): string
    {
        return __('Daily at :time', ['time' => self::masterInvariantsTime()]);
    }

    public static function dailyReconcileScheduleLabel(): string
    {
        return __('Daily at :time', ['time' => self::dailyReconcileTime()]);
    }

    public static function nightlyReconcileScheduleLabel(): string
    {
        return __('Daily at :time', ['time' => self::nightlyReconcileTime()]);
    }

    public static function bankAutoMatchScheduleLabel(): string
    {
        return __('Daily at :time', ['time' => self::bankAutoMatchTime()]);
    }

    public static function delinquencyDigestScheduleLabel(): string
    {
        return __('Daily at :time', ['time' => self::delinquencyDigestTime()]);
    }

    public static function statementsScheduleLabel(): string
    {
        return __('On day :day of each month at :time', [
            'day' => self::statementsDay(),
            'time' => self::statementsTime(),
        ]);
    }

    public static function announcementsScheduleLabel(): string
    {
        if (! self::dispatchAnnouncementsEnabled()) {
            return __('Disabled');
        }

        $interval = self::dispatchAnnouncementsIntervalMinutes();
        $cadence = self::pollingIntervalOptions()[$interval] ?? __('Every :n minutes', ['n' => $interval]);

        return __(':cadence (when announcements are due)', ['cadence' => $cadence]);
    }

    public static function onboardingGreetingScheduleLabel(): string
    {
        if (! self::onboardingGreetingEnabled()) {
            return __('Manual (scheduled catch-up disabled)');
        }

        return __('Daily at :time', ['time' => self::onboardingGreetingTime()]);
    }

    public static function lateFeesScheduleLabel(): string
    {
        if (! self::lateFeesEnabled()) {
            return __('Disabled');
        }

        return __('Daily at :time (also after each Apply contributions)', [
            'time' => self::lateFeesTime(),
        ]);
    }

    public static function loanDefaultsScheduleLabel(): string
    {
        if (! self::loanDefaultsEnabled()) {
            return __('Disabled');
        }

        return __('Daily at :time (also after each Apply loan repayments)', [
            'time' => self::loanDefaultsTime(),
        ]);
    }

    public static function emiCloseScheduleLabel(): string
    {
        return __('On day :day of each month at :time', [
            'day' => self::emiCloseDay(),
            'time' => self::emiCloseTime(),
        ]);
    }

    public static function cycleCloseScheduleLabel(): string
    {
        return __('Daily check at :time — runs on cycle start day (:day)', [
            'time' => self::cycleCloseTime(),
            'day' => self::cycleStartDayLabel(),
        ]);
    }

    public static function cycleInitScheduleLabel(): string
    {
        return __('Daily check at :time — runs on cycle start day (:day), right after close', [
            'time' => self::cycleInitTime(),
            'day' => self::cycleStartDayLabel(),
        ]);
    }

    /**
     * @param  list<int>  $days
     */
    private static function isDueNotifySlot(array $days, string $time, ?Carbon $businessDay, ?Carbon $wallClock): bool
    {
        $businessDay = $businessDay ?? BusinessDay::now();
        $wallClock = $wallClock ?? Carbon::now();

        if ($wallClock->format('H:i') !== $time) {
            return false;
        }

        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $start = $cycles->cycleStartAt($month, $year)->startOfDay();
        $dueEnd = $cycles->cycleDueEndAt($month, $year);

        if ($businessDay->lt($start) || $businessDay->gt($dueEnd)) {
            return false;
        }

        $offset = (int) $start->diffInDays($businessDay->copy()->startOfDay(), false);

        return in_array($offset, $days, true);
    }

    /**
     * @param  list<string>  $times
     */
    private static function isApplySlot(array $times, ?Carbon $businessDay, ?Carbon $wallClock): bool
    {
        $businessDay = $businessDay ?? BusinessDay::now();
        $wallClock = $wallClock ?? Carbon::now();

        if (! in_array($wallClock->format('H:i'), $times, true)) {
            return false;
        }

        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $start = $cycles->cycleStartAt($month, $year)->startOfDay();
        $dueEnd = $cycles->cycleDueEndAt($month, $year);

        return $businessDay->gte($start) && $businessDay->lte($dueEnd);
    }

    private static function isCalendarDayTimeSlot(int $day, string $time, ?Carbon $businessDay, ?Carbon $wallClock): bool
    {
        $businessDay = ($businessDay ?? BusinessDay::now())->copy()->startOfDay();
        $wallClock = $wallClock ?? Carbon::now();

        if ((int) $businessDay->day !== $day) {
            return false;
        }

        return self::isWallClockSlot($time, $wallClock);
    }

    private static function isWallClockSlot(string $time, ?Carbon $wallClock = null): bool
    {
        $wallClock = $wallClock ?? Carbon::now();

        return $wallClock->format('H:i') === $time;
    }

    private static function isPollingIntervalSlot(int $intervalMinutes, ?Carbon $wallClock = null): bool
    {
        $intervalMinutes = self::normalizeIntervalMinutes($intervalMinutes);
        $wallClock = $wallClock ?? Carbon::now();

        if ($intervalMinutes <= 1) {
            return true;
        }

        $epochMinute = intdiv((int) $wallClock->timestamp, 60);

        return ($epochMinute % $intervalMinutes) === 0;
    }

    private static function normalizeIntervalMinutes(mixed $value): int
    {
        $allowed = array_keys(self::pollingIntervalOptions());
        $minutes = is_numeric($value) ? (int) $value : 1;

        if (! in_array($minutes, $allowed, true)) {
            return 1;
        }

        return $minutes;
    }

    private static function resolveCalendarDay(string $key): int
    {
        $stored = self::get($key, null);

        if ($stored !== null && $stored !== '') {
            return max(1, min(28, (int) $stored));
        }

        return self::cycleStartDayLabel();
    }

    private static function cycleStartDayLabel(): int
    {
        // Registry labels resolve outside tenancy (TenantAwareScheduledCommand::execute).
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return 6;
        }

        return Setting::contributionCycleStartDay();
    }

    private static function get(string $key, mixed $default): mixed
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return self::defaults()[$key] ?? $default;
        }

        $value = Setting::get(self::GROUP, $key);

        return $value !== null && $value !== '' ? $value : $default;
    }

    private static function normalizeDayList(mixed $value): string
    {
        $days = self::parseDayList($value);

        return $days === [] ? (string) self::defaults()['contribution_due_notify_days'] : implode(',', $days);
    }

    /**
     * @return list<int>
     */
    private static function parseDayList(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[,\s]+/', trim((string) $value)) ?: [];
        }

        $days = [];

        foreach ($parts as $part) {
            if ($part === '' || ! is_numeric($part)) {
                continue;
            }

            $day = max(0, min(31, (int) $part));
            $days[$day] = $day;
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    private static function normalizeTimesList(mixed $value, int $max): string
    {
        $times = self::parseTimesList($value, $max);

        return $times === [] ? '06:00' : implode(',', $times);
    }

    /**
     * @return list<string>
     */
    private static function parseTimesList(mixed $value, int $max): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[,\s]+/', trim((string) $value)) ?: [];
        }

        $times = [];

        foreach ($parts as $part) {
            $normalized = self::normalizeClockTime($part);

            if ($normalized === null) {
                continue;
            }

            $times[$normalized] = $normalized;

            if (count($times) >= $max) {
                break;
            }
        }

        $times = array_values($times);
        sort($times);

        return $times;
    }

    private static function normalizeClockTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    private static function boolFromStored(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
