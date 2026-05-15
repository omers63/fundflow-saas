<?php

namespace App\Support;

use Filament\Schemas\Components\Concerns\HasLabel;

/**
 * Helpers for Laravel localization. Prefer Filament's {@see HasLabel::translateLabel()}
 * on form fields, table columns, filters, and actions where possible.
 */
final class Lang
{
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
            $out[$key] = is_string($value) ? __($value) : $value;
        }

        return $out;
    }
}
