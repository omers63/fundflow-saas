<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use App\Support\StatementSettings;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use Dompdf\Dompdf;

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
        // DomPDF layout + Arabic shaping routinely exceeds PHP-FPM's default 30s limit.
        @set_time_limit(0);

        $html = view($view, $data)->render();

        // Always shape Arabic glyphs when present — English PDFs may still include Arabic names.
        $html = self::shapeArabicHtml($html);

        $pdf = PdfFacade::loadHTML($html)->setPaper($paper, $orientation);
        self::ensureCustomFontsRegistered($pdf->getDomPDF());

        return $pdf;
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

    public static function ensureCustomFontsRegistered(Dompdf $dompdf): void
    {
        $amiri = StatementSettings::customFontPath(StatementSettings::FONT_AMIRI);

        if ($amiri === null) {
            return;
        }

        $metrics = $dompdf->getFontMetrics();
        $fileUri = 'file://'.$amiri;
        $families = $metrics->getFontFamilies();
        $existing = $families['amiri'] ?? [];

        // Parse the TTF only for normal/bold. Extra numeric weights were re-parsing
        // the same 550KB file on every miss and bloating storage/fonts.
        foreach (['normal', 'bold'] as $weight) {
            $path = $existing[$weight] ?? null;

            if (is_string($path) && (is_file($path.'.ttf') || is_file($path))) {
                continue;
            }

            $metrics->registerFont(
                [
                    'family' => 'Amiri',
                    'style' => 'normal',
                    'weight' => $weight,
                ],
                $fileUri,
            );
        }

        $families = $metrics->getFontFamilies();
        $amiriFamily = $families['amiri'] ?? [];
        $normal = $amiriFamily['normal'] ?? null;
        $bold = $amiriFamily['bold'] ?? null;

        if (! is_string($normal) || ! is_string($bold)) {
            return;
        }

        // DomPDF falls back to Helvetica (Arabic → ????) when CSS weights like
        // 600/800 are missing — alias them to the already-parsed faces.
        $aliased = [
            'normal' => $normal,
            'bold' => $bold,
            '500' => $normal,
            '600' => $bold,
            '800' => $bold,
        ];

        if (
            ($amiriFamily['500'] ?? null) === $normal
            && ($amiriFamily['600'] ?? null) === $bold
            && ($amiriFamily['800'] ?? null) === $bold
        ) {
            return;
        }

        $metrics->setFontFamily('Amiri', $aliased);
    }
}
