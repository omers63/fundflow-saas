<?php

declare(strict_types=1);

namespace App\Support;

final class MemberFaq
{
    /**
     * @return list<array{question: string, answer: string}>
     */
    public static function items(): array
    {
        $locale = app()->getLocale();
        $path = lang_path($locale.'/member_faq.php');

        if (! is_file($path)) {
            $path = lang_path(config('app.fallback_locale', 'en').'/member_faq.php');
        }

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $items */
        $items = require $path;

        return is_array($items) ? $items : [];
    }
}
