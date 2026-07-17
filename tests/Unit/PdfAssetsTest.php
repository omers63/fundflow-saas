<?php

declare(strict_types=1);

use App\Support\Pdf\PdfAssets;

it('returns sar symbol as svg data uri', function (): void {
    expect(PdfAssets::sarSymbolDataUri())
        ->toStartWith('data:image/svg+xml;base64,');
});

it('can recolor the sar symbol fill for colored pdf backgrounds', function (): void {
    $default = base64_decode(substr(PdfAssets::sarSymbolDataUri(), strlen('data:image/svg+xml;base64,')));
    $white = base64_decode(substr(PdfAssets::sarSymbolDataUri('#ffffff'), strlen('data:image/svg+xml;base64,')));

    expect($default)->toContain('fill="#334155"')
        ->and($white)->toContain('fill="#ffffff"')
        ->and($white)->not->toContain('fill="#334155"');
});
