<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Support\PublicPageSettingsForm;
use App\Models\Tenant\User;
use App\Support\AppBrand;
use App\Support\BrandAppearanceSettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    App::setLocale('en');
});

test('theme colors use a hex color picker and brand images use an image editor', function () {
    $logoUpload = PublicPageSettingsForm::fundLogoUpload();

    expect(PublicPageSettingsForm::colorPicker('theme_color', 'Theme'))
        ->toBeInstanceOf(ColorPicker::class)
        ->and(PublicPageSettingsForm::colorPicker('theme_color', 'Theme')->getFormat())->toBe('hex')
        ->and($logoUpload)->toBeInstanceOf(FileUpload::class)
        ->and($logoUpload->hasImageEditor())->toBeTrue()
        ->and($logoUpload->getHintActions())->toHaveCount(2);
});

test('public page settings show color pickers, current icons, and the image editor', function () {
    $admin = User::create([
        'name' => 'Appearance Editor Admin',
        'email' => 'appearance-editor@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'public-page::tab'])
        ->assertSet('settingsTab', 'public-page::tab')
        ->assertSee(__('Public / PWA theme color'))
        ->assertSeeHtml('fi-fo-color-picker')
        ->assertSeeHtml('hasImageEditor: true')
        ->assertSee(__('Edit image'))
        ->assertSee(__('Draw on image'))
        ->assertSee(__('Current logo'))
        ->assertSee(__('Current icon'))
        ->assertSee(AppBrand::iconWebPath('pwa_192'), false)
        ->assertSee(BrandAppearanceSettings::DEFAULT_THEME_COLOR, false)
        ->assertSet('data.icon_pwa_192', fn (mixed $value): bool => in_array(BrandAppearanceSettings::bundledIconPath('pwa_192'), (array) $value, true))
        ->assertSet('data.fund_logo', fn (mixed $value): bool => in_array(BrandAppearanceSettings::BUNDLED_LOGO_PATH, (array) $value, true));
});

test('saving the color picker persists the selected theme color', function () {
    $admin = User::create([
        'name' => 'Appearance Color Admin',
        'email' => 'appearance-color@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'public-page::tab'])
        ->set('data.theme_color', '#10b981')
        ->call('save')
        ->assertNotified();

    expect(BrandAppearanceSettings::themeColor())->toBe('#10B981');
});

test('applying a pixel drawing puts a png on the icon upload', function () {
    $admin = User::create([
        'name' => 'Pixel Draw Admin',
        'email' => 'pixel-draw@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $image = imagecreatetruecolor(1, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagesetpixel($image, 0, 0, imagecolorallocatealpha($image, 0, 128, 0, 0));
    ob_start();
    imagepng($image);
    $dataUrl = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    imagedestroy($image);

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class, ['settingsTab' => 'public-page::tab'])
        ->call('applyIconPixelEdit', 'icon_favicon_16', $dataUrl)
        ->assertNotified(__('Drawing applied. Save settings to keep it.'));

    $path = array_values((array) $component->get('data.icon_favicon_16'))[0] ?? '';

    expect($path)->toContain('drawn-icon_favicon_16-')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

test('icon pixel editor exposes zoom controls', function () {
    $html = view('filament.tenant.partials.icon-pixel-editor', [
        'field' => 'icon_favicon_16',
        'src' => '/icons/favicon-16x16.png',
        'width' => 16,
        'height' => 16,
    ])->render();

    expect($html)
        ->toContain(__('Zoom in'))
        ->toContain(__('Zoom out'))
        ->toContain(__('Fit'))
        ->toContain(__('Pan'))
        ->toContain(__('Pixel select'))
        ->toContain(__('Line'))
        ->toContain(__('Rectangle'))
        ->toContain(__('Ellipse'))
        ->toContain(__('Fill shapes'))
        ->toContain(__('Grid'))
        ->toContain(__('Opacity'))
        ->toContain(__('Clear canvas'))
        ->toContain(__('Actual size (:width×:height)', ['width' => 16, 'height' => 16]))
        ->toContain('x-ref="preview"')
        ->toContain('ff-pixel-editor__preview')
        ->not->toContain('Ctrl+scroll');
});
