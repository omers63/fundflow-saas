<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class PublicPageContentSettings
{
    public const GROUP = 'public_content';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = AppBrand::publicContent();
        $defaults['features'] = json_encode(self::defaultFeatures(), JSON_UNESCAPED_UNICODE);
        $defaults['steps'] = json_encode(self::defaultSteps(), JSON_UNESCAPED_UNICODE);

        return $defaults;
    }

    /**
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    public static function defaultFeatures(): array
    {
        $features = AppBrand::publicContent()['features'] ?? [];

        return is_array($features) ? $features : [];
    }

    /**
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    public static function defaultSteps(): array
    {
        $steps = AppBrand::publicContent()['steps'] ?? [];

        return is_array($steps) ? $steps : [];
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
    public static function allForForm(): array
    {
        $all = self::all();
        $all['nav_show_features'] = filter_var($all['nav_show_features'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $all['nav_show_how_it_works'] = filter_var($all['nav_show_how_it_works'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $all['nav_show_check_status'] = filter_var($all['nav_show_check_status'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $all['show_stats'] = filter_var($all['show_stats'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $all['landing_features'] = self::featureRows();
        $all['landing_steps'] = self::stepRows();
        unset($all['features'], $all['steps']);

        return $all;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        $values = $state;
        $values['features'] = json_encode(self::normalizeCards($state['landing_features'] ?? []), JSON_UNESCAPED_UNICODE);
        $values['steps'] = json_encode(self::normalizeCards($state['landing_steps'] ?? []), JSON_UNESCAPED_UNICODE);
        unset($values['landing_features'], $values['landing_steps']);

        self::save($values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (in_array($key, ['nav_show_features', 'nav_show_how_it_works', 'nav_show_check_status', 'show_stats'], true)) {
                Setting::set(self::GROUP, $key, $value ? '1' : '0');

                continue;
            }

            if (in_array($key, ['features', 'steps'], true)) {
                Setting::set(self::GROUP, $key, is_string($value) ? $value : json_encode(self::normalizeCards((array) $value), JSON_UNESCAPED_UNICODE));

                continue;
            }

            Setting::set(self::GROUP, $key, is_scalar($value) ? (string) $value : '');
        }
    }

    public static function text(string $key, array $replace = []): string
    {
        $locale = app()->getLocale();
        $localized = $locale === 'ar' ? "{$key}_ar" : "{$key}_en";
        $fallback = $locale === 'ar' ? "{$key}_en" : "{$key}_ar";

        $value = trim((string) self::get($localized));

        if ($value === '') {
            $value = trim((string) self::get($fallback));
        }

        if ($replace === []) {
            return $value;
        }

        $pairs = [];

        foreach ($replace as $search => $replacement) {
            $pairs[':'.$search] = (string) $replacement;
        }

        return strtr($value, $pairs);
    }

    public static function html(string $key): string
    {
        return self::text($key);
    }

    public static function enabled(string $key): bool
    {
        return filter_var(self::get($key, '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    public static function features(): array
    {
        return self::localizedCards(self::decodeCards(self::get('features'), self::defaultFeatures()));
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    public static function steps(): array
    {
        return self::localizedCards(self::decodeCards(self::get('steps'), self::defaultSteps()));
    }

    /**
     * Repeater state (bilingual rows).
     *
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    public static function featureRows(): array
    {
        return self::decodeCards(self::get('features'), self::defaultFeatures());
    }

    /**
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    public static function stepRows(): array
    {
        return self::decodeCards(self::get('steps'), self::defaultSteps());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null || $value === '') {
            return self::defaults()[$key] ?? $default;
        }

        return $value;
    }

    /**
     * @param  list<array{title_en?: mixed, title_ar?: mixed, body_en?: mixed, body_ar?: mixed}>  $rows
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    private static function normalizeCards(array $rows): array
    {
        $cards = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $titleEn = trim((string) ($row['title_en'] ?? ''));
            $titleAr = trim((string) ($row['title_ar'] ?? ''));
            $bodyEn = trim((string) ($row['body_en'] ?? ''));
            $bodyAr = trim((string) ($row['body_ar'] ?? ''));

            if ($titleEn === '' && $titleAr === '' && $bodyEn === '' && $bodyAr === '') {
                continue;
            }

            $cards[] = [
                'title_en' => $titleEn,
                'title_ar' => $titleAr,
                'body_en' => $bodyEn,
                'body_ar' => $bodyAr,
            ];
        }

        return $cards;
    }

    /**
     * @param  list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>  $fallback
     * @return list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>
     */
    private static function decodeCards(mixed $raw, array $fallback): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }

        if (! is_array($decoded) || $decoded === []) {
            return $fallback;
        }

        $cards = self::normalizeCards($decoded);

        return $cards === [] ? $fallback : $cards;
    }

    /**
     * @param  list<array{title_en: string, title_ar: string, body_en: string, body_ar: string}>  $rows
     * @return list<array{title: string, body: string}>
     */
    private static function localizedCards(array $rows): array
    {
        $locale = app()->getLocale();
        $cards = [];

        foreach ($rows as $row) {
            $title = $locale === 'ar'
                ? (trim($row['title_ar']) !== '' ? $row['title_ar'] : $row['title_en'])
                : (trim($row['title_en']) !== '' ? $row['title_en'] : $row['title_ar']);
            $body = $locale === 'ar'
                ? (trim($row['body_ar']) !== '' ? $row['body_ar'] : $row['body_en'])
                : (trim($row['body_en']) !== '' ? $row['body_en'] : $row['body_ar']);

            $cards[] = [
                'title' => $title,
                'body' => $body,
            ];
        }

        return $cards;
    }
}
