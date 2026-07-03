<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class LedgerSettings
{
    public const GROUP = 'ledger';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'show_manual_credit_debit' => false,
            'show_split_reverse' => false,
            'show_edit_delete' => true,
        ];
    }

    public static function showManualCreditDebit(): bool
    {
        return self::booleanSetting('show_manual_credit_debit');
    }

    public static function showSplitReverse(): bool
    {
        return self::booleanSetting('show_split_reverse');
    }

    public static function showEditDelete(): bool
    {
        return self::booleanSetting('show_edit_delete');
    }

    private static function booleanSetting(string $key): bool
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null) {
            return (bool) (self::defaults()[$key] ?? false);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        return [
            'ledger_show_manual_credit_debit' => self::showManualCreditDebit(),
            'ledger_show_split_reverse' => self::showSplitReverse(),
            'ledger_show_edit_delete' => self::showEditDelete(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(
            self::GROUP,
            'show_manual_credit_debit',
            (bool) ($state['ledger_show_manual_credit_debit'] ?? false) ? '1' : '0',
        );
        Setting::set(
            self::GROUP,
            'show_split_reverse',
            (bool) ($state['ledger_show_split_reverse'] ?? false) ? '1' : '0',
        );
        Setting::set(
            self::GROUP,
            'show_edit_delete',
            (bool) ($state['ledger_show_edit_delete'] ?? true) ? '1' : '0',
        );
    }
}
