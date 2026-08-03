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
            'contribution_due_notify_days' => '0,7,14,21,28',
            'contribution_due_notify_time' => '09:00',
            'loan_due_notify_days' => '0,7,14,21,28',
            'loan_due_notify_time' => '09:00',
            // One or two HH:MM times (comma-separated) while the cycle is open.
            'contribution_apply_times' => '06:00',
            'loan_apply_times' => '06:00',
            // Day of month for monthly reconcile + statements (explicit; same as cycle start).
            'month_boundary_day' => 6,
            'month_boundary_time' => '00:30',
            // Cycle transition (close previous / init next) times on cycle start day.
            'cycle_close_time' => '00:30',
            'cycle_init_time' => '00:35',
            // EMI window close (explicit; same as cycle start).
            'emi_close_day' => 6,
            'emi_close_time' => '00:45',
            // Daily fund / bank / delinquency jobs (legacy single-time, kept for backward compatibility).
            'master_invariants_time' => '06:00',
            'daily_reconcile_time' => '06:20',
            'nightly_reconcile_time' => '06:30',
            'bank_auto_match_time' => '08:00',
            'delinquency_digest_time' => '07:30',
            // Cron-like cadence for scoped reconciliation/maintenance jobs.
            'master_invariants_frequency' => 'daily',
            'master_invariants_weekdays' => '',
            'master_invariants_month_days' => '',
            'master_invariants_times' => '06:00',
            'daily_reconcile_frequency' => 'daily',
            'daily_reconcile_weekdays' => '',
            'daily_reconcile_month_days' => '',
            'daily_reconcile_times' => '06:20',
            'nightly_reconcile_frequency' => 'daily',
            'nightly_reconcile_weekdays' => '',
            'nightly_reconcile_month_days' => '',
            'nightly_reconcile_times' => '06:30',
            'bank_auto_match_frequency' => 'daily',
            'bank_auto_match_weekdays' => '',
            'bank_auto_match_month_days' => '',
            'bank_auto_match_times' => '08:00',
            'delinquency_digest_frequency' => 'daily',
            'delinquency_digest_weekdays' => '',
            'delinquency_digest_month_days' => '',
            'delinquency_digest_times' => '07:30',
            'fund_status_digest_frequency' => 'daily',
            'fund_status_digest_weekdays' => '',
            'fund_status_digest_month_days' => '',
            'fund_status_digest_times' => '04:00',
            // Statements (defaults follow month-boundary day/time when unset).
            'statements_day' => 6,
            'statements_time' => '00:30',
            // Messaging / chained maintenance.
            'dispatch_announcements_enabled' => true,
            'dispatch_announcements_interval_minutes' => 15,
            'onboarding_greeting_enabled' => true,
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
            'notify_fund_status_digest' => true,
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
            'automation_master_invariants_frequency' => self::masterInvariantsFrequency(),
            'automation_master_invariants_weekdays' => self::masterInvariantsWeekdays(),
            'automation_master_invariants_month_days' => self::masterInvariantsMonthDays(),
            'automation_master_invariants_times' => implode(',', self::masterInvariantsTimes()),
            'automation_daily_reconcile_frequency' => self::dailyReconcileFrequency(),
            'automation_daily_reconcile_weekdays' => self::dailyReconcileWeekdays(),
            'automation_daily_reconcile_month_days' => self::dailyReconcileMonthDays(),
            'automation_daily_reconcile_times' => implode(',', self::dailyReconcileTimes()),
            'automation_nightly_reconcile_frequency' => self::nightlyReconcileFrequency(),
            'automation_nightly_reconcile_weekdays' => self::nightlyReconcileWeekdays(),
            'automation_nightly_reconcile_month_days' => self::nightlyReconcileMonthDays(),
            'automation_nightly_reconcile_times' => implode(',', self::nightlyReconcileTimes()),
            'automation_bank_auto_match_frequency' => self::bankAutoMatchFrequency(),
            'automation_bank_auto_match_weekdays' => self::bankAutoMatchWeekdays(),
            'automation_bank_auto_match_month_days' => self::bankAutoMatchMonthDays(),
            'automation_bank_auto_match_times' => implode(',', self::bankAutoMatchTimes()),
            'automation_delinquency_digest_frequency' => self::delinquencyDigestFrequency(),
            'automation_delinquency_digest_weekdays' => self::delinquencyDigestWeekdays(),
            'automation_delinquency_digest_month_days' => self::delinquencyDigestMonthDays(),
            'automation_delinquency_digest_times' => implode(',', self::delinquencyDigestTimes()),
            'automation_fund_status_digest_frequency' => self::fundStatusDigestFrequency(),
            'automation_fund_status_digest_weekdays' => self::fundStatusDigestWeekdays(),
            'automation_fund_status_digest_month_days' => self::fundStatusDigestMonthDays(),
            'automation_fund_status_digest_times' => implode(',', self::fundStatusDigestTimes()),
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
            'automation_notify_fund_status_digest' => self::notifyFundStatusDigest(),
            'automation_notify_monthly_statements' => self::notifyMonthlyStatements(),
            'automation_notify_announcements' => self::notifyAnnouncements(),
            'automation_notify_onboarding_greeting' => self::notifyOnboardingGreeting(),
        ];
    }

    /**
     * Persist canonical automation schedule defaults for a tenant.
     *
     * Used on fresh tenant seed and by migrations that backfill missing keys.
     * When {@see $onlyMissing} is true, existing tenant values are left unchanged.
     *
     * @return int Number of setting rows written
     */
    public static function seedDefaults(bool $onlyMissing = false): int
    {
        $written = 0;
        $existing = Setting::getGroup(self::GROUP);

        // Derive cadence *_times from legacy *_time for existing tenants so custom times are preserved.
        $legacyTimeMap = [
            'master_invariants_times' => 'master_invariants_time',
            'daily_reconcile_times' => 'daily_reconcile_time',
            'nightly_reconcile_times' => 'nightly_reconcile_time',
            'bank_auto_match_times' => 'bank_auto_match_time',
            'delinquency_digest_times' => 'delinquency_digest_time',
        ];

        foreach (self::defaults() as $key => $value) {
            if ($onlyMissing && array_key_exists($key, $existing)) {
                continue;
            }

            // When backfilling cadence *_times on an existing tenant, derive from their stored legacy *_time.
            if ($onlyMissing && isset($legacyTimeMap[$key])) {
                $legacyKey = $legacyTimeMap[$key];
                $legacyStored = $existing[$legacyKey] ?? null;

                if ($legacyStored !== null && $legacyStored !== '') {
                    $normalized = self::normalizeClockTime($legacyStored);
                    Setting::set(self::GROUP, $key, $normalized ?? (string) $value);
                    $written++;

                    continue;
                }
            }

            Setting::set(self::GROUP, $key, self::serializeDefaultValue($value));
            $written++;
        }

        return $written;
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

        foreach ([
            'master_invariants' => ['default_times' => '06:00'],
            'daily_reconcile' => ['default_times' => '06:20'],
            'nightly_reconcile' => ['default_times' => '06:30'],
            'bank_auto_match' => ['default_times' => '08:00'],
            'delinquency_digest' => ['default_times' => '07:30'],
            'fund_status_digest' => ['default_times' => '04:00'],
        ] as $job => $meta) {
            $freq = self::normalizeCadenceFrequency($state["automation_{$job}_frequency"] ?? 'daily');
            Setting::set(self::GROUP, "{$job}_frequency", $freq);
            Setting::set(
                self::GROUP,
                "{$job}_weekdays",
                self::serializeCadenceList(self::parseCadenceWeekdays($state["automation_{$job}_weekdays"] ?? [])),
            );
            Setting::set(
                self::GROUP,
                "{$job}_month_days",
                self::serializeCadenceList(self::parseCadenceMonthDays($state["automation_{$job}_month_days"] ?? [])),
            );
            $timesRaw = $state["automation_{$job}_times"] ?? $meta['default_times'];
            $times = self::parseCadenceTimes($timesRaw, $meta['default_times']);
            Setting::set(self::GROUP, "{$job}_times", self::serializeCadenceList($times) ?: $meta['default_times']);
        }

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
        Setting::set(self::GROUP, 'onboarding_greeting_enabled', ($state['automation_onboarding_greeting_enabled'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'onboarding_greeting_time', self::normalizeClockTime($state['automation_onboarding_greeting_time'] ?? null) ?? '10:00');
        Setting::set(self::GROUP, 'late_fees_enabled', ($state['automation_late_fees_enabled'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'late_fees_time', self::normalizeClockTime($state['automation_late_fees_time'] ?? null) ?? '06:05');
        Setting::set(self::GROUP, 'loan_defaults_enabled', ($state['automation_loan_defaults_enabled'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'loan_defaults_time', self::normalizeClockTime($state['automation_loan_defaults_time'] ?? null) ?? '06:05');

        Setting::set(self::GROUP, 'notify_contribution_due', ($state['automation_notify_contribution_due'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_loan_due', ($state['automation_notify_loan_due'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_delinquency_digest', ($state['automation_notify_delinquency_digest'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_reconciliation_digest', ($state['automation_notify_reconciliation_digest'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'notify_fund_status_digest', ($state['automation_notify_fund_status_digest'] ?? true) ? '1' : '0');
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

    // -------------------------------------------------------------------------
    // Cron-like cadence getters for the 5 scoped reconciliation/maintenance jobs
    // -------------------------------------------------------------------------

    public static function masterInvariantsFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('master_invariants_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function masterInvariantsWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('master_invariants_weekdays', ''));
    }

    /** @return list<int> */
    public static function masterInvariantsMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('master_invariants_month_days', ''));
    }

    /** @return list<string> */
    public static function masterInvariantsTimes(): array
    {
        return self::parseCadenceTimes(self::get('master_invariants_times', ''), self::masterInvariantsTime());
    }

    public static function dailyReconcileFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('daily_reconcile_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function dailyReconcileWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('daily_reconcile_weekdays', ''));
    }

    /** @return list<int> */
    public static function dailyReconcileMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('daily_reconcile_month_days', ''));
    }

    /** @return list<string> */
    public static function dailyReconcileTimes(): array
    {
        return self::parseCadenceTimes(self::get('daily_reconcile_times', ''), self::dailyReconcileTime());
    }

    public static function nightlyReconcileFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('nightly_reconcile_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function nightlyReconcileWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('nightly_reconcile_weekdays', ''));
    }

    /** @return list<int> */
    public static function nightlyReconcileMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('nightly_reconcile_month_days', ''));
    }

    /** @return list<string> */
    public static function nightlyReconcileTimes(): array
    {
        return self::parseCadenceTimes(self::get('nightly_reconcile_times', ''), self::nightlyReconcileTime());
    }

    public static function bankAutoMatchFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('bank_auto_match_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function bankAutoMatchWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('bank_auto_match_weekdays', ''));
    }

    /** @return list<int> */
    public static function bankAutoMatchMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('bank_auto_match_month_days', ''));
    }

    /** @return list<string> */
    public static function bankAutoMatchTimes(): array
    {
        return self::parseCadenceTimes(self::get('bank_auto_match_times', ''), self::bankAutoMatchTime());
    }

    public static function delinquencyDigestFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('delinquency_digest_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function delinquencyDigestWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('delinquency_digest_weekdays', ''));
    }

    /** @return list<int> */
    public static function delinquencyDigestMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('delinquency_digest_month_days', ''));
    }

    /** @return list<string> */
    public static function delinquencyDigestTimes(): array
    {
        return self::parseCadenceTimes(self::get('delinquency_digest_times', ''), self::delinquencyDigestTime());
    }

    public static function fundStatusDigestFrequency(): string
    {
        return self::normalizeCadenceFrequency(self::get('fund_status_digest_frequency', 'daily'));
    }

    /** @return list<int> */
    public static function fundStatusDigestWeekdays(): array
    {
        return self::parseCadenceWeekdays(self::get('fund_status_digest_weekdays', ''));
    }

    /** @return list<int> */
    public static function fundStatusDigestMonthDays(): array
    {
        return self::parseCadenceMonthDays(self::get('fund_status_digest_month_days', ''));
    }

    /** @return list<string> */
    public static function fundStatusDigestTimes(): array
    {
        return self::parseCadenceTimes(self::get('fund_status_digest_times', ''), '04:00');
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

    public static function notifyFundStatusDigest(): bool
    {
        return self::boolFromStored(self::get('notify_fund_status_digest', null), (bool) self::defaults()['notify_fund_status_digest']);
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
        return self::isCadenceSlot(
            self::masterInvariantsFrequency(),
            self::masterInvariantsWeekdays(),
            self::masterInvariantsMonthDays(),
            self::masterInvariantsTimes(),
            $wallClock,
        );
    }

    public static function isDailyReconcileSlot(?Carbon $wallClock = null): bool
    {
        return self::isCadenceSlot(
            self::dailyReconcileFrequency(),
            self::dailyReconcileWeekdays(),
            self::dailyReconcileMonthDays(),
            self::dailyReconcileTimes(),
            $wallClock,
        );
    }

    public static function isNightlyReconcileSlot(?Carbon $wallClock = null): bool
    {
        return self::isCadenceSlot(
            self::nightlyReconcileFrequency(),
            self::nightlyReconcileWeekdays(),
            self::nightlyReconcileMonthDays(),
            self::nightlyReconcileTimes(),
            $wallClock,
        );
    }

    public static function isBankAutoMatchSlot(?Carbon $wallClock = null): bool
    {
        return self::isCadenceSlot(
            self::bankAutoMatchFrequency(),
            self::bankAutoMatchWeekdays(),
            self::bankAutoMatchMonthDays(),
            self::bankAutoMatchTimes(),
            $wallClock,
        );
    }

    public static function isDelinquencyDigestSlot(?Carbon $wallClock = null): bool
    {
        return self::isCadenceSlot(
            self::delinquencyDigestFrequency(),
            self::delinquencyDigestWeekdays(),
            self::delinquencyDigestMonthDays(),
            self::delinquencyDigestTimes(),
            $wallClock,
        );
    }

    public static function isFundStatusDigestSlot(?Carbon $wallClock = null): bool
    {
        return self::isCadenceSlot(
            self::fundStatusDigestFrequency(),
            self::fundStatusDigestWeekdays(),
            self::fundStatusDigestMonthDays(),
            self::fundStatusDigestTimes(),
            $wallClock,
        );
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
        return self::cadenceScheduleLabel(
            self::masterInvariantsFrequency(),
            self::masterInvariantsWeekdays(),
            self::masterInvariantsMonthDays(),
            self::masterInvariantsTimes(),
        );
    }

    public static function dailyReconcileScheduleLabel(): string
    {
        return self::cadenceScheduleLabel(
            self::dailyReconcileFrequency(),
            self::dailyReconcileWeekdays(),
            self::dailyReconcileMonthDays(),
            self::dailyReconcileTimes(),
        );
    }

    public static function nightlyReconcileScheduleLabel(): string
    {
        return self::cadenceScheduleLabel(
            self::nightlyReconcileFrequency(),
            self::nightlyReconcileWeekdays(),
            self::nightlyReconcileMonthDays(),
            self::nightlyReconcileTimes(),
        );
    }

    public static function bankAutoMatchScheduleLabel(): string
    {
        return self::cadenceScheduleLabel(
            self::bankAutoMatchFrequency(),
            self::bankAutoMatchWeekdays(),
            self::bankAutoMatchMonthDays(),
            self::bankAutoMatchTimes(),
        );
    }

    public static function delinquencyDigestScheduleLabel(): string
    {
        return self::cadenceScheduleLabel(
            self::delinquencyDigestFrequency(),
            self::delinquencyDigestWeekdays(),
            self::delinquencyDigestMonthDays(),
            self::delinquencyDigestTimes(),
        );
    }

    public static function fundStatusDigestScheduleLabel(): string
    {
        return self::cadenceScheduleLabel(
            self::fundStatusDigestFrequency(),
            self::fundStatusDigestWeekdays(),
            self::fundStatusDigestMonthDays(),
            self::fundStatusDigestTimes(),
        );
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

    /**
     * Cadence-based slot check for scoped reconciliation/maintenance jobs.
     *
     * @param  list<int>  $weekdays  ISO weekdays (1=Mon … 7=Sun) for weekly cadence
     * @param  list<int>  $monthDays  Day-of-month (1–28) for monthly cadence
     * @param  list<string>  $times  HH:MM times list
     */
    private static function isCadenceSlot(
        string $frequency,
        array $weekdays,
        array $monthDays,
        array $times,
        ?Carbon $wallClock = null,
    ): bool {
        if ($frequency === 'disabled') {
            return false;
        }

        $wallClock = $wallClock ?? Carbon::now();

        if (! in_array($wallClock->format('H:i'), $times, true)) {
            return false;
        }

        return match ($frequency) {
            'weekly' => in_array((int) $wallClock->isoWeekday(), $weekdays, true),
            'monthly' => in_array((int) $wallClock->day, $monthDays, true),
            default => true, // daily
        };
    }

    /**
     * @param  list<int>  $weekdays
     * @param  list<int>  $monthDays
     * @param  list<string>  $times
     */
    private static function cadenceScheduleLabel(
        string $frequency,
        array $weekdays,
        array $monthDays,
        array $times,
    ): string {
        if ($frequency === 'disabled') {
            return __('Disabled');
        }

        $timesStr = implode(', ', $times) ?: '—';

        if ($frequency === 'weekly') {
            $dayNames = array_map(fn (int $d) => Carbon::now()->startOfWeek()->addDays($d - 1)->isoFormat('ddd'), $weekdays);

            return __('Weekly on :days at :times', [
                'days' => implode(', ', $dayNames),
                'times' => $timesStr,
            ]);
        }

        if ($frequency === 'monthly') {
            return __('Monthly on day(s) :days at :times', [
                'days' => implode(', ', $monthDays),
                'times' => $timesStr,
            ]);
        }

        return __('Daily at :times', ['times' => $timesStr]);
    }

    private static function normalizeCadenceFrequency(mixed $value): string
    {
        $allowed = ['daily', 'weekly', 'monthly', 'disabled'];
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : 'daily';
    }

    /**
     * Parse a comma-separated weekday list (ISO 1–7).
     *
     * @return list<int>
     */
    private static function parseCadenceWeekdays(mixed $value): array
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

            $day = (int) $part;

            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    /**
     * Parse a comma-separated month-day list (1–28).
     *
     * @return list<int>
     */
    private static function parseCadenceMonthDays(mixed $value): array
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

            $day = (int) $part;

            if ($day >= 1 && $day <= 28) {
                $days[$day] = $day;
            }
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    /**
     * Parse a comma-separated times list, falling back to $legacyTime when empty.
     *
     * @return list<string>
     */
    private static function parseCadenceTimes(mixed $value, string $legacyTime): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[,\s]+/', trim((string) $value)) ?: [];
        }

        $times = [];

        foreach ($parts as $part) {
            $normalized = self::normalizeClockTime($part);

            if ($normalized !== null) {
                $times[$normalized] = $normalized;
            }
        }

        if ($times === []) {
            $fallback = self::normalizeClockTime($legacyTime);

            if ($fallback !== null) {
                $times[$fallback] = $fallback;
            }
        }

        $times = array_values($times);
        sort($times);

        return $times;
    }

    private static function serializeCadenceList(array $values): string
    {
        return implode(',', $values);
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

    private static function serializeDefaultValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
