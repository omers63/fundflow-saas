<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use InvalidArgumentException;

/**
 * Halts operational batch posting when reconciliation reports critical imbalance.
 */
final class BatchPostingGate
{
    public const SETTING_GROUP = 'system';

    public const HALT_KEY = 'batch_posting_halted';

    public const HALT_REASON_KEY = 'batch_posting_halt_reason';

    public function isHalted(): bool
    {
        if ((bool) Setting::get(self::SETTING_GROUP, self::HALT_KEY, false)) {
            return true;
        }

        return ReconciliationException::query()
            ->open()
            ->where('exception_code', 'MASTER_IMBALANCE_UNRESOLVED')
            ->where('severity', 'critical')
            ->exists();
    }

    public function halt(?string $reason = null): void
    {
        Setting::set(self::SETTING_GROUP, self::HALT_KEY, true);
        Setting::set(self::SETTING_GROUP, self::HALT_REASON_KEY, $reason ?? __('Critical master account imbalance'));
    }

    public function clear(): void
    {
        Setting::set(self::SETTING_GROUP, self::HALT_KEY, false);
        Setting::set(self::SETTING_GROUP, self::HALT_REASON_KEY, null);
    }

    public function reason(): ?string
    {
        $reason = Setting::get(self::SETTING_GROUP, self::HALT_REASON_KEY);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertAllowed(): void
    {
        if ($this->isHalted()) {
            throw new InvalidArgumentException(
                $this->reason() ?? __('Batch posting is halted until reconciliation critical issues are resolved.')
            );
        }
    }
}
