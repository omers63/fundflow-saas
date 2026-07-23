<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use Carbon\Carbon;

/**
 * Tenant-configurable cron slots for Automation (notify / apply / month-boundary jobs).
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $all = array_merge(self::defaults(), Setting::getGroup(self::GROUP));

        return [
            'automation_contribution_due_notify_days' => (string) ($all['contribution_due_notify_days'] ?? self::defaults()['contribution_due_notify_days']),
            'automation_contribution_due_notify_time' => (string) ($all['contribution_due_notify_time'] ?? self::defaults()['contribution_due_notify_time']),
            'automation_loan_due_notify_days' => (string) ($all['loan_due_notify_days'] ?? self::defaults()['loan_due_notify_days']),
            'automation_loan_due_notify_time' => (string) ($all['loan_due_notify_time'] ?? self::defaults()['loan_due_notify_time']),
            'automation_contribution_apply_times' => (string) ($all['contribution_apply_times'] ?? self::defaults()['contribution_apply_times']),
            'automation_loan_apply_times' => (string) ($all['loan_apply_times'] ?? self::defaults()['loan_apply_times']),
            'automation_month_boundary_day' => self::monthBoundaryDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
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
        $stored = self::get('month_boundary_day', null);

        if ($stored !== null && $stored !== '') {
            return max(1, min(28, (int) $stored));
        }

        return Setting::contributionCycleStartDay();
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
        $businessDay = ($businessDay ?? BusinessDay::now())->copy()->startOfDay();
        $wallClock = $wallClock ?? Carbon::now();

        if ((int) $businessDay->day !== self::monthBoundaryDay()) {
            return false;
        }

        return $wallClock->format('H:i') === '00:30';
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
        return __('On day :day of each month at 00:30', [
            'day' => self::monthBoundaryDay(),
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
}
