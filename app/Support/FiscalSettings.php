<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Carbon\Carbon;

final class FiscalSettings
{
    public const GROUP = 'fiscal';

    public const PURGE_ARCHIVE_THEN_DELETE = 'archive_then_delete';

    public const PURGE_DELETE_ONLY = 'delete_only';

    public const PURGE_RETAIN_7Y = 'retain_7y';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'fiscal_year_start_month' => 1,
            'fiscal_year_start_day' => 1,
            'books_closed_through' => null,
            'current_fiscal_year_label' => null,
            'purge_policy' => self::PURGE_ARCHIVE_THEN_DELETE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function purgePolicyOptions(): array
    {
        return [
            self::PURGE_ARCHIVE_THEN_DELETE => __('Archive then delete'),
            self::PURGE_DELETE_ONLY => __('Delete only'),
            self::PURGE_RETAIN_7Y => __('Retain 7 years'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    /**
     * @return array<string, mixed>
     */
    public static function forForm(): array
    {
        $all = self::all();

        return [
            'fiscal_year_start_month' => (int) ($all['fiscal_year_start_month'] ?? 1),
            'fiscal_year_start_day' => (int) ($all['fiscal_year_start_day'] ?? 1),
            'books_closed_through' => filled($all['books_closed_through'] ?? null)
                ? (string) $all['books_closed_through']
                : null,
            'current_fiscal_year_label' => filled($all['current_fiscal_year_label'] ?? null)
                ? (string) $all['current_fiscal_year_label']
                : null,
            'purge_policy' => (string) ($all['purge_policy'] ?? self::PURGE_ARCHIVE_THEN_DELETE),
        ];
    }

    public static function fiscalYearStartMonth(): int
    {
        return max(1, min(12, (int) self::get('fiscal_year_start_month', 1)));
    }

    public static function fiscalYearStartDay(): int
    {
        return max(1, min(28, (int) self::get('fiscal_year_start_day', 1)));
    }

    public static function booksClosedThrough(): ?Carbon
    {
        $value = self::get('books_closed_through');

        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function currentFiscalYearLabel(): ?string
    {
        $value = self::get('current_fiscal_year_label');

        return is_string($value) && filled($value) ? $value : null;
    }

    public static function purgePolicy(): string
    {
        $value = self::get('purge_policy', self::PURGE_ARCHIVE_THEN_DELETE);

        return is_string($value) && filled($value) ? $value : self::PURGE_ARCHIVE_THEN_DELETE;
    }

    public static function requiresExportBeforePurge(): bool
    {
        return in_array(self::purgePolicy(), [
            self::PURGE_ARCHIVE_THEN_DELETE,
            self::PURGE_DELETE_ONLY,
        ], true);
    }

    public static function includesTierBPurge(): bool
    {
        return in_array(self::purgePolicy(), [
            self::PURGE_ARCHIVE_THEN_DELETE,
            self::PURGE_DELETE_ONLY,
        ], true);
    }

    public static function applyBooksClosedThrough(Carbon $periodEnd): void
    {
        Setting::set(self::GROUP, 'books_closed_through', $periodEnd->toDateString());
    }

    public static function setCurrentFiscalYearLabel(string $label): void
    {
        Setting::set(self::GROUP, 'current_fiscal_year_label', $label);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function saveFromForm(array $values): void
    {
        Setting::set(self::GROUP, 'fiscal_year_start_month', max(1, min(12, (int) ($values['fiscal_year_start_month'] ?? 1))));
        Setting::set(self::GROUP, 'fiscal_year_start_day', max(1, min(28, (int) ($values['fiscal_year_start_day'] ?? 1))));

        if (array_key_exists('purge_policy', $values)) {
            $policy = (string) $values['purge_policy'];
            if (array_key_exists($policy, self::purgePolicyOptions())) {
                Setting::set(self::GROUP, 'purge_policy', $policy);
            }
        }

        if (array_key_exists('current_fiscal_year_label', $values) && filled($values['current_fiscal_year_label'])) {
            Setting::set(self::GROUP, 'current_fiscal_year_label', (string) $values['current_fiscal_year_label']);
        }
    }

    private static function get(string $key, mixed $default = null): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
