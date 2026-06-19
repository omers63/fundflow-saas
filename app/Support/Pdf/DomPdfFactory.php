<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF as DomPdfDocument;

final class DomPdfFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function loadView(
        string $view,
        array $data = [],
        string $paper = 'a4',
        string $orientation = 'portrait',
    ): DomPdfDocument {
        $html = view($view, $data)->render();

        if (app()->getLocale() === 'ar') {
            $html = self::shapeArabicHtml($html);
        }

        return PdfFacade::loadHTML($html)->setPaper($paper, $orientation);
    }

    public static function shapeArabicHtml(string $html): string
    {
        if (! preg_match('/\p{Arabic}/u', $html)) {
            return $html;
        }

        $arabic = new Arabic;
        $placeholders = [];

        $html = preg_replace_callback(
            '/<(style|script)\b[^>]*>[\s\S]*?<\/\1>/i',
            static function (array $matches) use (&$placeholders): string {
                $key = '___FF_AR_BLOCK_'.count($placeholders).'___';
                $placeholders[$key] = $matches[0];

                return $key;
            },
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/>([^<]+)</u',
            static function (array $matches) use ($arabic): string {
                $text = $matches[1];

                if (! preg_match('/\p{Arabic}/u', $text)) {
                    return '>'.$text.'<';
                }

                return '>'.$arabic->utf8Glyphs($text, 500, hindo: false, forcertl: false).'<';
            },
            $html,
        ) ?? $html;

        foreach ($placeholders as $key => $block) {
            $html = str_replace($key, $block, $html);
        }

        return $html;
    }
}
