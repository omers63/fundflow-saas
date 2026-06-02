<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class ArabicTypography
{
    private const ARABIC_SEGMENT_PATTERN = '/(\p{Arabic}+(?:\p{M}*\p{Arabic}*)*)/u';

    /**
     * Column names that typically hold a person or fund display name.
     *
     * @var list<string>
     */
    private const PERSON_NAME_COLUMNS = [
        'name',
        'member_name',
        'member.name',
        'loan.member.name',
        'guarantor.name',
        'borrower.name',
        'sender.name',
        'from_user.name',
    ];

    public static function containsArabic(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        return (bool) preg_match('/\p{Arabic}/u', $text);
    }

    public static function isPersonNameColumn(string $columnName): bool
    {
        if (in_array($columnName, self::PERSON_NAME_COLUMNS, true)) {
            return true;
        }

        return str_ends_with($columnName, '.name');
    }

    /**
     * Render text with Arabic runs in RTL order (enhanced styling when enabled in settings).
     */
    public static function display(?string $text): Htmlable
    {
        if ($text === null || $text === '') {
            return new HtmlString(e('—'));
        }

        if (! self::containsArabic($text)) {
            return new HtmlString(e($text));
        }

        return self::wrapRtlSegments($text, ArabicDisplaySettings::enhancedNameStyle());
    }

    private static function wrapRtlSegments(string $text, bool $useNameClass): Htmlable
    {
        $parts = preg_split(self::ARABIC_SEGMENT_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return new HtmlString(e($text));
        }

        $html = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/\p{Arabic}/u', $part) === 1) {
                $class = $useNameClass ? ' class="ff-arabic-name"' : '';
                $html .= '<bdi dir="rtl" lang="ar"'.$class.'>'.e($part).'</bdi>';
            } else {
                $html .= e($part);
            }
        }

        return new HtmlString($html);
    }

    /**
     * @return array<string, string>
     */
    public static function nameHtmlAttributes(?string $text): array
    {
        if (! self::containsArabic($text)) {
            return [];
        }

        if (! ArabicDisplaySettings::enhancedNameStyle()) {
            return ['dir' => 'rtl', 'lang' => 'ar'];
        }

        return ['class' => 'ff-arabic-name', 'dir' => 'rtl', 'lang' => 'ar'];
    }
}
