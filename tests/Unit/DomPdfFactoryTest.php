<?php

declare(strict_types=1);

use App\Support\Pdf\DomPdfFactory;
use App\Support\StatementSettings;

it('shapes arabic html into presentation forms for dompdf', function (): void {
    $source = '<html><body><p>جدول سداد القرض</p></body></html>';
    $shaped = DomPdfFactory::shapeArabicHtml($source);

    expect($shaped)->not->toBe($source)
        ->toContain('<html><body><p>')
        ->toContain('</p></body></html>')
        ->and(preg_match('/[\x{FE70}-\x{FEFF}\x{FB50}-\x{FDFF}]/u', $shaped))->toBe(1);
});

it('preserves html tag order when shaping mixed arabic and english text nodes', function (): void {
    $source = '<title>صندوق — Loan schedule #160</title>';

    $shaped = DomPdfFactory::shapeArabicHtml($source);

    expect($shaped)->toStartWith('<title>')
        ->toEndWith('</title>')
        ->toContain('Loan schedule #160');
});

it('does not alter html without arabic script', function (): void {
    $source = '<html><body><p>Loan repayment schedule</p></body></html>';

    expect(DomPdfFactory::shapeArabicHtml($source))->toBe($source);
});

it('leaves style blocks untouched when shaping arabic html', function (): void {
    $source = <<<'HTML'
<html><head><style>body { direction: rtl; }</style></head><body><p>جدول</p></body></html>
HTML;

    $shaped = DomPdfFactory::shapeArabicHtml($source);

    expect($shaped)->toContain('body { direction: rtl; }');
});

it('preserves western digits when shaping arabic text nodes', function (): void {
    $source = '<p>1,500.00</p>';

    expect(DomPdfFactory::shapeArabicHtml($source))->toBe($source);
});

it('preserves western digits in ltr amount spans adjacent to arabic labels', function (): void {
    $source = '<tr><td>المبلغ</td><td><span class="amount">1,500.00</span></td></tr>';
    $shaped = DomPdfFactory::shapeArabicHtml($source);

    expect($shaped)->toContain('1,500.00')
        ->not->toMatch('/[٠-٩]/u');
});

it('registers amiri normal and bold then aliases numeric css weights', function (): void {
    $amiri = StatementSettings::customFontPath(StatementSettings::FONT_AMIRI);

    if ($amiri === null) {
        $this->markTestSkipped('Amiri font is not installed.');
    }

    $fontDir = sys_get_temp_dir().'/ff-dompdf-fonts-'.uniqid('', true);
    mkdir($fontDir);

    try {
        $dompdf = new Dompdf\Dompdf([
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
            'chroot' => base_path(),
            'isRemoteEnabled' => false,
            'allowedProtocols' => [
                'file://' => ['rules' => []],
                'data://' => ['rules' => []],
            ],
        ]);

        DomPdfFactory::ensureCustomFontsRegistered($dompdf);
        DomPdfFactory::ensureCustomFontsRegistered($dompdf);

        $family = $dompdf->getFontMetrics()->getFontFamilies()['amiri'] ?? [];

        expect($family)->toHaveKeys(['normal', 'bold', '500', '600', '800'])
            ->and($family['500'])->toBe($family['normal'])
            ->and($family['600'])->toBe($family['bold'])
            ->and($family['800'])->toBe($family['bold']);
    } finally {
        foreach (glob($fontDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($fontDir);
    }
});
