<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant-level pause for cron-driven scheduled automation.
 *
 * Does not stop host cron ({@see schedule:run}); paused tenants soft-skip
 * scheduled command bodies so manual "Run now" can still work.
 */
final class AutomationSchedulerGate
{
    public const SETTING_GROUP = 'system';

    public const PAUSED_KEY = 'automation_scheduler_paused';

    public const PAUSED_REASON_KEY = 'automation_scheduler_paused_reason';

    public function isPaused(): bool
    {
        return (bool) Setting::get(self::SETTING_GROUP, self::PAUSED_KEY, false);
    }

    public function pause(?string $reason = null): void
    {
        Setting::set(self::SETTING_GROUP, self::PAUSED_KEY, true);
        Setting::set(
            self::SETTING_GROUP,
            self::PAUSED_REASON_KEY,
            $reason ?? __('Paused from Automation'),
        );
    }

    public function resume(): void
    {
        Setting::set(self::SETTING_GROUP, self::PAUSED_KEY, false);
        Setting::set(self::SETTING_GROUP, self::PAUSED_REASON_KEY, null);
    }

    public function reason(): ?string
    {
        $reason = Setting::get(self::SETTING_GROUP, self::PAUSED_REASON_KEY);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
