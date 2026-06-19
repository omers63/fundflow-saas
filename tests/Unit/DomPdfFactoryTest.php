<?php

declare(strict_types=1);

use App\Support\Pdf\DomPdfFactory;

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
