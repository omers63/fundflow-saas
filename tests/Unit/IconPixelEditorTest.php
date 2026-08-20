<?php

declare(strict_types=1);

use App\Support\IconPixelEditor;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Storage::fake('public');
});

function iconPixelEditorPngDataUrl(): string
{
    $image = imagecreatetruecolor(1, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 255, 0, 0, 0));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    imagedestroy($image);

    return 'data:image/png;base64,'.base64_encode($png);
}

it('stores a drawn png at the icon slot size', function () {
    $path = IconPixelEditor::store('icon_favicon_16', iconPixelEditorPngDataUrl());

    expect($path)->toStartWith('fund-branding/icons/drawn-icon_favicon_16-')
        ->and(IconPixelEditor::isDrawnPath($path))->toBeTrue();

    Storage::disk('public')->assertExists($path);

    $stored = imagecreatefromstring((string) Storage::disk('public')->get($path));
    expect(imagesx($stored))->toBe(16)
        ->and(imagesy($stored))->toBe(16);
    imagedestroy($stored);
});

it('rejects an unknown field and a non-png payload', function () {
    expect(fn () => IconPixelEditor::store('not_an_icon', iconPixelEditorPngDataUrl()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => IconPixelEditor::store('fund_logo', 'data:image/jpeg;base64,aaaa'))
        ->toThrow(InvalidArgumentException::class);
});
