<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class GovernanceSettings
{
    public const GROUP = 'governance';

    public static function quorumPercent(): float
    {
        return (float) Setting::get(self::GROUP, 'quorum_percent', 50);
    }

    public static function boardOnlyVoting(): bool
    {
        return (string) Setting::get(self::GROUP, 'board_only_voting', '0') === '1';
    }

    public static function expenseMotionThreshold(): float
    {
        return (float) Setting::get(self::GROUP, 'expense_motion_threshold', 10000);
    }

    public static function settingChangeRequiresMotion(): bool
    {
        return (string) Setting::get(self::GROUP, 'setting_change_requires_motion', '0') === '1';
    }

    /**
     * Setting group keys that require an approved motion when enforcement is on.
     *
     * @return list<string>
     */
    public static function protectedSettingGroups(): array
    {
        $raw = (string) Setting::get(self::GROUP, 'protected_setting_groups', 'loan,contribution');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function distributionMotionThreshold(): float
    {
        return (float) Setting::get(self::GROUP, 'distribution_motion_threshold', 5000);
    }

    /**
     * @param  array{
     *     quorum_percent?: float|int|string,
     *     board_only_voting?: bool|string,
     *     expense_motion_threshold?: float|int|string,
     *     setting_change_requires_motion?: bool|string,
     *     protected_setting_groups?: string,
     *     distribution_motion_threshold?: float|int|string
     * }  $data
     */
    public static function save(array $data): void
    {
        if (array_key_exists('quorum_percent', $data)) {
            Setting::set(self::GROUP, 'quorum_percent', (string) $data['quorum_percent']);
        }

        if (array_key_exists('board_only_voting', $data)) {
            Setting::set(self::GROUP, 'board_only_voting', ! empty($data['board_only_voting']) ? '1' : '0');
        }

        if (array_key_exists('expense_motion_threshold', $data)) {
            Setting::set(self::GROUP, 'expense_motion_threshold', (string) $data['expense_motion_threshold']);
        }

        if (array_key_exists('setting_change_requires_motion', $data)) {
            Setting::set(self::GROUP, 'setting_change_requires_motion', ! empty($data['setting_change_requires_motion']) ? '1' : '0');
        }

        if (array_key_exists('protected_setting_groups', $data)) {
            Setting::set(self::GROUP, 'protected_setting_groups', (string) $data['protected_setting_groups']);
        }

        if (array_key_exists('distribution_motion_threshold', $data)) {
            Setting::set(self::GROUP, 'distribution_motion_threshold', (string) $data['distribution_motion_threshold']);
        }
    }
}
