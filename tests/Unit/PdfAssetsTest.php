<?php

declare(strict_types=1);

use App\Support\Pdf\PdfAssets;

it('returns sar symbol as svg data uri', function (): void {
    expect(PdfAssets::sarSymbolDataUri())
        ->toStartWith('data:image/svg+xml;base64,');
});
