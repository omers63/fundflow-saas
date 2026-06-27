<?php

declare(strict_types=1);

namespace App\Support;

final class NotificationPlainText
{
    public static function from(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $normalized = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
        $normalized = preg_replace('/<\/(p|div|section|li|dt|dd|h[1-6])>/i', "\n", $normalized) ?? $normalized;

        $text = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
