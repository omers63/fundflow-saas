<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class ReconciliationDigestSettings
{
    public const GROUP = 'reconciliation';

    public const KEY_DIGEST_PUSH_ENABLED = 'digest_push_enabled';

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        return [
            'reconciliation_digest_push_enabled' => self::digestPushEnabled(),
        ];
    }

    public static function digestPushEnabled(): bool
    {
        $value = Setting::get(self::GROUP, self::KEY_DIGEST_PUSH_ENABLED);

        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(
            self::GROUP,
            self::KEY_DIGEST_PUSH_ENABLED,
            ($state['reconciliation_digest_push_enabled'] ?? true) ? '1' : '0',
        );
    }
}
