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
     * Title-case Latin UI labels. Leave Arabic (and other non-Latin) copy unchanged —
     * Str::title() is for English display polish and must not rewrite translated text.
     */
    public static function formatUiLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return $label;
        }

        if (preg_match('/\p{Arabic}|\p{Hebrew}|\p{Cyrillic}|\p{Han}/u', $label) === 1) {
            return $label;
        }

        // Keep Latin acronyms / machine tokens (HTTP, URL, CSV, PHP-FPM, …).
        if (preg_match('/^[A-Z0-9]+(?:[.\-][A-Z0-9]+)*$/', $label) === 1) {
            return $label;
        }

        return Str::title($label);
    }

    /**
     * Translate a UI string, trying common casing variants used across Filament
     * (get_model_label → "loan", navigationLabel → "Loans", headline → "Loan").
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function translateUi(string $key, array $replace = []): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        foreach (self::translationCandidates($key) as $candidate) {
            $translated = __($candidate, $replace);

            if ($translated !== $candidate) {
                return self::formatUiLabel($translated);
            }
        }

        return self::formatUiLabel($key);
    }

    /**
     * @return list<string>
     */
    public static function translationCandidates(string $key): array
    {
        $candidates = [
            $key,
            Str::ucfirst($key),
            Str::title($key),
            Str::headline($key),
            Str::lower($key),
        ];

        if (str_contains($key, '_')) {
            $candidates[] = Str::headline(str_replace('_', ' ', $key));
        }

        return array_values(array_unique(array_filter($candidates, fn(string $value): bool => $value !== '')));
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

        return self::translateUi($key, $replace);
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
            $out[$key] = is_string($value) ? self::translateUi($value) : $value;
        }

        return $out;
    }
}
