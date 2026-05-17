<?php

namespace App\Support;

use Filament\Schemas\Components\Concerns\HasLabel;
use Illuminate\Support\Str;

/**
 * Helpers for Laravel localization. Prefer Filament's {@see HasLabel::translateLabel()}
 * on form fields, table columns, filters, and actions where possible.
 */
final class Lang
{
    /**
     * Title-case UI labels so each word starts with an uppercase letter (e.g. "member name" → "Member Name").
     */
    public static function formatUiLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return $label;
        }

        return Str::title($label);
    }

    /**
     * Translate a string key, then title-case the result for display.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function ui(?string $key, array $replace = []): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        return self::formatUiLabel(__($key, $replace));
    }

    /**
     * Title-case an already-translated string (e.g. trans_choice output).
     */
    public static function uiText(string $text): string
    {
        return self::formatUiLabel($text);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function formatLabeledRow(array $row, array $keys = ['label', 'sub', 'title', 'body', 'description']): array
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = self::formatUiLabel($row[$key]);
            }
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function formatLabeledRows(array $rows, array $keys = ['label', 'sub', 'title', 'body', 'description']): array
    {
        return array_map(
            fn (array $row): array => self::formatLabeledRow($row, $keys),
            $rows,
        );
    }

    /**
     * Translate each option label while preserving keys (for Select, SelectFilter, Radio, etc.).
     *
     * @param  array<string|int, string|int|float>  $options
     * @return array<string|int, string>
     */
    public static function transOptions(array $options): array
    {
        $out = [];

        foreach ($options as $key => $value) {
            $out[$key] = is_string($value) ? self::formatUiLabel(__($value)) : $value;
        }

        return $out;
    }
}
