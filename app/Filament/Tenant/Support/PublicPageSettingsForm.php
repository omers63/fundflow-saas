<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Support\AppBrand;
use App\Support\BrandAppearanceSettings;
use App\Support\PublicPageSettings;
use App\Support\TenantAssetUrl;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

final class PublicPageSettingsForm
{
    /**
     * Appearance, icons, and public chrome sections for the Public page settings tab.
     *
     * @return list<Section>
     */
    public static function appearanceSections(): array
    {
        return [
            Section::make(__('Theme'))
                ->description(__('Click the swatch to open the color editor. Current greens, sky, and member purple are the defaults.'))
                ->columns(2)
                ->schema([
                    self::colorPicker('theme_color', __('Public / PWA theme color'))
                        ->helperText(__('Browser theme bar, landing accents, and PWA theme_color.')),
                    self::colorPicker('background_color', __('PWA background color')),
                    self::colorPicker('tenant_panel_primary', __('Admin panel primary'))
                        ->helperText(__('Filament admin (tenant) panel primary color.')),
                    self::colorPicker('member_panel_primary', __('Member panel primary'))
                        ->helperText(__('Filament member portal primary color.')),
                ]),
            Section::make(__('App icons'))
                ->description(__('Each slot is prefilled with the current icon. Click the pencil to crop, or the brush to draw pixels. Remove the file to restore the bundled default.'))
                ->columns(3)
                ->schema(self::iconUploadFields()),
            Section::make(__('Header & navigation'))
                ->description(__('Public site top bar. Empty optional banner is the current default (hidden).'))
                ->columns(2)
                ->schema([
                    TextInput::make('nav_home_en')->label(__('Home (English)'))->required()->maxLength(80),
                    TextInput::make('nav_home_ar')->label(__('Home (Arabic)'))->required()->maxLength(80),
                    TextInput::make('nav_features_en')->label(__('Features (English)'))->required()->maxLength(80),
                    TextInput::make('nav_features_ar')->label(__('Features (Arabic)'))->required()->maxLength(80),
                    TextInput::make('nav_how_it_works_en')->label(__('How it works (English)'))->required()->maxLength(80),
                    TextInput::make('nav_how_it_works_ar')->label(__('How it works (Arabic)'))->required()->maxLength(80),
                    TextInput::make('nav_check_status_en')->label(__('Check status (English)'))->required()->maxLength(120),
                    TextInput::make('nav_check_status_ar')->label(__('Check status (Arabic)'))->required()->maxLength(120),
                    TextInput::make('nav_member_login_en')->label(__('Member login (English)'))->required()->maxLength(80),
                    TextInput::make('nav_member_login_ar')->label(__('Member login (Arabic)'))->required()->maxLength(80),
                    TextInput::make('nav_apply_en')->label(__('Apply (English)'))->required()->maxLength(80),
                    TextInput::make('nav_apply_ar')->label(__('Apply (Arabic)'))->required()->maxLength(80),
                    Toggle::make('nav_show_features')->label(__('Show Features link')),
                    Toggle::make('nav_show_how_it_works')->label(__('Show How it works link')),
                    Toggle::make('nav_show_check_status')->label(__('Show application status link')),
                    Textarea::make('header_banner_en')
                        ->label(__('Header banner (English)'))
                        ->rows(2)
                        ->columnSpanFull(),
                    Textarea::make('header_banner_ar')
                        ->label(__('Header banner (Arabic)'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText(__('Optional HTML shown under the navigation. Leave empty to hide.')),
                ]),
            Section::make(__('Footer'))
                ->description(__('Public site footer copy. Contact details are still taken from Public contact below.'))
                ->columns(2)
                ->schema([
                    Textarea::make('footer_tagline_en')->label(__('Tagline (English)'))->rows(2)->required(),
                    Textarea::make('footer_tagline_ar')->label(__('Tagline (Arabic)'))->rows(2)->required(),
                    TextInput::make('footer_links_heading_en')->label(__('Links heading (English)'))->required()->maxLength(80),
                    TextInput::make('footer_links_heading_ar')->label(__('Links heading (Arabic)'))->required()->maxLength(80),
                    TextInput::make('footer_contact_heading_en')->label(__('Contact heading (English)'))->required()->maxLength(80),
                    TextInput::make('footer_contact_heading_ar')->label(__('Contact heading (Arabic)'))->required()->maxLength(80),
                    TextInput::make('footer_copyright_en')
                        ->label(__('Copyright (English)'))
                        ->required()
                        ->helperText(__('Placeholders: :year and :fund.')),
                    TextInput::make('footer_copyright_ar')
                        ->label(__('Copyright (Arabic)'))
                        ->required(),
                    TextInput::make('footer_terms_en')->label(__('Terms link (English)'))->required()->maxLength(120),
                    TextInput::make('footer_terms_ar')->label(__('Terms link (Arabic)'))->required()->maxLength(120),
                    Textarea::make('footer_contact_empty_en')->label(__('Empty contact (English)'))->rows(2)->required(),
                    Textarea::make('footer_contact_empty_ar')->label(__('Empty contact (Arabic)'))->rows(2)->required(),
                    Textarea::make('footer_extra_en')->label(__('Extra footer (English)'))->rows(2)->columnSpanFull(),
                    Textarea::make('footer_extra_ar')
                        ->label(__('Extra footer (Arabic)'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText(__('Optional HTML under the tagline. Leave empty to hide.')),
                ]),
            Section::make(__('Sidebar'))
                ->description(__('Optional public-page sidebar. The Filament admin/member sidebar still uses the fund logo above. Leave empty to keep the current full-width layout.'))
                ->schema([
                    Textarea::make('sidebar_html_en')
                        ->label(__('Sidebar (English)'))
                        ->rows(4),
                    Textarea::make('sidebar_html_ar')
                        ->label(__('Sidebar (Arabic)'))
                        ->rows(4)
                        ->helperText(__('HTML is allowed. Leave both empty to hide the sidebar.')),
                ]),
            Section::make(__('Landing page'))
                ->description(__('Hero, stats, features, how-it-works, and call to action. Current public copy is the default.'))
                ->columns(2)
                ->collapsed()
                ->schema([
                    Textarea::make('meta_description_en')->label(__('Meta description (English)'))->rows(2)->required(),
                    Textarea::make('meta_description_ar')->label(__('Meta description (Arabic)'))->rows(2)->required(),
                    TextInput::make('pwa_description_en')->label(__('PWA description (English)'))->required()->maxLength(255),
                    TextInput::make('pwa_description_ar')->label(__('PWA description (Arabic)'))->required()->maxLength(255),
                    TextInput::make('pwa_short_name_en')
                        ->label(__('PWA short name (English)'))
                        ->maxLength(12)
                        ->helperText(__('Optional. Defaults to a shortened fund name.')),
                    TextInput::make('pwa_short_name_ar')->label(__('PWA short name (Arabic)'))->maxLength(12),
                    TextInput::make('hero_badge_en')->label(__('Hero badge (English)'))->required()->maxLength(120),
                    TextInput::make('hero_badge_ar')->label(__('Hero badge (Arabic)'))->required()->maxLength(120),
                    TextInput::make('hero_title_before_en')->label(__('Hero title before accent (English)'))->required()->maxLength(80),
                    TextInput::make('hero_title_before_ar')->label(__('Hero title before accent (Arabic)'))->required()->maxLength(80),
                    TextInput::make('hero_title_accent_en')->label(__('Hero title accent (English)'))->required()->maxLength(80),
                    TextInput::make('hero_title_accent_ar')->label(__('Hero title accent (Arabic)'))->required()->maxLength(80),
                    TextInput::make('hero_title_after_en')->label(__('Hero title after accent (English)'))->required()->maxLength(80),
                    TextInput::make('hero_title_after_ar')->label(__('Hero title after accent (Arabic)'))->required()->maxLength(80),
                    Textarea::make('hero_lead_en')->label(__('Hero lead (English)'))->rows(3)->required()->columnSpanFull(),
                    Textarea::make('hero_lead_ar')->label(__('Hero lead (Arabic)'))->rows(3)->required()->columnSpanFull(),
                    Toggle::make('show_stats')->label(__('Show stats strip')),
                    TextInput::make('stat_members_fallback_en')->label(__('Members fallback (English)'))->required()->maxLength(20),
                    TextInput::make('stat_members_fallback_ar')->label(__('Members fallback (Arabic)'))->required()->maxLength(20),
                    TextInput::make('stat_members_label_en')->label(__('Members label (English)'))->required()->maxLength(80),
                    TextInput::make('stat_members_label_ar')->label(__('Members label (Arabic)'))->required()->maxLength(80),
                    TextInput::make('stat_months_value_en')->label(__('Months value (English)'))->required()->maxLength(20),
                    TextInput::make('stat_months_value_ar')->label(__('Months value (Arabic)'))->required()->maxLength(20),
                    TextInput::make('stat_months_label_en')->label(__('Months label (English)'))->required()->maxLength(80),
                    TextInput::make('stat_months_label_ar')->label(__('Months label (Arabic)'))->required()->maxLength(80),
                    TextInput::make('stat_transparent_value_en')->label(__('Transparent value (English)'))->required()->maxLength(20),
                    TextInput::make('stat_transparent_value_ar')->label(__('Transparent value (Arabic)'))->required()->maxLength(20),
                    TextInput::make('stat_transparent_label_en')->label(__('Transparent label (English)'))->required()->maxLength(80),
                    TextInput::make('stat_transparent_label_ar')->label(__('Transparent label (Arabic)'))->required()->maxLength(80),
                    TextInput::make('stat_rates_value_en')->label(__('Rates value (English)'))->required()->maxLength(20),
                    TextInput::make('stat_rates_value_ar')->label(__('Rates value (Arabic)'))->required()->maxLength(20),
                    TextInput::make('stat_rates_label_en')->label(__('Rates label (English)'))->required()->maxLength(80),
                    TextInput::make('stat_rates_label_ar')->label(__('Rates label (Arabic)'))->required()->maxLength(80),
                    TextInput::make('features_heading_en')->label(__('Features heading (English)'))->required()->maxLength(120),
                    TextInput::make('features_heading_ar')->label(__('Features heading (Arabic)'))->required()->maxLength(120),
                    Textarea::make('features_subheading_en')->label(__('Features subheading (English)'))->rows(2)->required(),
                    Textarea::make('features_subheading_ar')->label(__('Features subheading (Arabic)'))->rows(2)->required(),
                    Repeater::make('landing_features')
                        ->label(__('Feature cards'))
                        ->schema(self::cardSchema())
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['title_en'] ?? __('Feature'))
                        ->collapsible()
                        ->reorderable()
                        ->addActionLabel(__('Add feature'))
                        ->maxItems(12)
                        ->columnSpanFull(),
                    TextInput::make('how_heading_en')->label(__('How it works heading (English)'))->required()->maxLength(120),
                    TextInput::make('how_heading_ar')->label(__('How it works heading (Arabic)'))->required()->maxLength(120),
                    Textarea::make('how_subheading_en')->label(__('How it works subheading (English)'))->rows(2)->required(),
                    Textarea::make('how_subheading_ar')->label(__('How it works subheading (Arabic)'))->rows(2)->required(),
                    Repeater::make('landing_steps')
                        ->label(__('How it works steps'))
                        ->schema(self::cardSchema())
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['title_en'] ?? __('Step'))
                        ->collapsible()
                        ->reorderable()
                        ->addActionLabel(__('Add step'))
                        ->maxItems(8)
                        ->columnSpanFull(),
                    TextInput::make('cta_heading_en')->label(__('CTA heading (English)'))->required()->maxLength(160),
                    TextInput::make('cta_heading_ar')->label(__('CTA heading (Arabic)'))->required()->maxLength(160),
                    Textarea::make('cta_body_en')->label(__('CTA body (English)'))->rows(2)->required(),
                    Textarea::make('cta_body_ar')->label(__('CTA body (Arabic)'))->rows(2)->required(),
                    TextInput::make('cta_apply_en')->label(__('CTA apply (English)'))->required()->maxLength(80),
                    TextInput::make('cta_apply_ar')->label(__('CTA apply (Arabic)'))->required()->maxLength(80),
                    TextInput::make('cta_status_en')->label(__('CTA status (English)'))->required()->maxLength(80),
                    TextInput::make('cta_status_ar')->label(__('CTA status (Arabic)'))->required()->maxLength(80),
                ]),
        ];
    }

    public static function colorPicker(string $name, string $label): ColorPicker
    {
        return ColorPicker::make($name)
            ->label($label)
            ->hex()
            ->required()
            ->regex('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/');
    }

    public static function fundLogoUpload(): FileUpload
    {
        return self::editableImageUpload(
            'fund_logo',
            'fund-branding',
            192,
            192,
            fn (): string => PublicPageSettings::fundLogoUrl(),
        )
            ->label(__('Fund logo'))
            ->columnSpanFull()
            ->aboveContent(fn (FileUpload $component): HtmlString => self::currentImagePreview(
                self::previewUrlFromState($component, PublicPageSettings::fundLogoUrl()),
                __('Current logo'),
            ))
            ->helperText(__('Prefills the current logo. Click the pencil to crop, or the brush to draw pixels. Remove the file to restore the FundFlow logo. PWA, favicon, and notification icons are configured in App icons.'));
    }

    /**
     * @return list<FileUpload>
     */
    private static function iconUploadFields(): array
    {
        $labels = [
            'favicon_16' => __('Favicon 16×16'),
            'favicon_32' => __('Favicon 32×32 (browser tab)'),
            'apple_touch' => __('Apple touch icon'),
            'pwa_72' => __('PWA icon 72×72'),
            'pwa_96' => __('PWA icon 96×96'),
            'pwa_128' => __('PWA icon 128×128'),
            'pwa_144' => __('PWA icon 144×144'),
            'pwa_152' => __('PWA icon 152×152'),
            'pwa_192' => __('PWA icon 192×192 (maskable)'),
            'pwa_384' => __('PWA icon 384×384'),
            'pwa_512' => __('PWA icon 512×512 (maskable)'),
            'notification_icon' => __('Notification icon 192×192'),
            'notification_badge' => __('Notification badge 96×96'),
        ];

        $fields = [];

        foreach (BrandAppearanceSettings::ICON_SLOTS as $slot => $meta) {
            [$width, $height] = self::iconDimensions($meta['sizes']);

            $fields[] = self::editableImageUpload(
                BrandAppearanceSettings::iconKey($slot),
                'fund-branding/icons',
                $width,
                $height,
                fn () => BrandAppearanceSettings::iconUrl($slot),
            )
                ->label($labels[$slot] ?? $slot)
                ->aboveContent(fn (FileUpload $component): HtmlString => self::currentImagePreview(
                    self::previewUrlFromState($component, BrandAppearanceSettings::iconUrl($slot)),
                    __('Current icon'),
                ))
                ->helperText(__('Default: :path. Click the pencil to crop, or the brush to draw pixels.', ['path' => AppBrand::iconWebPath($slot)]));
        }

        return $fields;
    }

    private static function editableImageUpload(string $name, string $directory, int $width, int $height, Closure $previewUrl): FileUpload
    {
        $viewport = max($width, $height, 256);

        return FileUpload::make($name)
            ->image()
            ->imageEditor()
            ->imageEditorAspectRatioOptions(['1:1', null])
            ->imageEditorViewportWidth($viewport)
            ->imageEditorViewportHeight($viewport)
            ->imageEditorEmptyFillColor('rgba(0, 0, 0, 0)')
            ->imagePreviewHeight('10rem')
            ->openable()
            ->downloadable()
            ->disk('public')
            ->directory($directory)
            ->maxSize(2048)
            ->acceptedFileTypes([
                'image/png',
                'image/jpeg',
                'image/webp',
                'image/svg+xml',
            ])
            ->extraAttributes(['class' => 'ff-editable-image-upload'])
            ->extraFieldWrapperAttributes(['class' => 'ff-editable-image-upload'])
            ->hintActions([
                self::editImageHintAction($name),
                self::drawImageHintAction($name, $width, $height, $previewUrl),
            ]);
    }

    private static function editImageHintAction(string $name): Action
    {
        return Action::make('editImage_'.$name)
            ->icon(Heroicon::PencilSquare)
            ->iconButton()
            ->label(__('Edit image'))
            ->tooltip(__('Edit image'))
            ->color('gray')
            ->alpineClickHandler(<<<'JS'
                const wrap = $el.closest('.ff-editable-image-upload') ?? $el.closest('.fi-fo-field-wrp');
                const edit = wrap?.querySelector('.filepond--action-edit-item');
                if (edit) {
                    edit.click();
                    return;
                }
                wrap?.querySelector('input.filepond--browser')?.click();
            JS);
    }

    private static function drawImageHintAction(string $name, int $width, int $height, Closure $previewUrl): Action
    {
        return Action::make('drawImage_'.$name)
            ->icon(Heroicon::PaintBrush)
            ->iconButton()
            ->label(__('Draw on image'))
            ->tooltip(__('Draw on image'))
            ->color('gray')
            ->modalHeading(__('Draw on image'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema([])
            ->modalContent(fn (Action $action): View => view('filament.tenant.partials.icon-pixel-editor', [
                'field' => $name,
                'src' => self::drawSourceUrl($action, $name, $previewUrl),
                'width' => $width,
                'height' => $height,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    private static function drawSourceUrl(Action $action, string $name, Closure $previewUrl): string
    {
        $livewire = $action->getLivewire();
        $state = is_object($livewire) && isset($livewire->data[$name]) ? $livewire->data[$name] : [];
        $url = self::previewUrlFromStateValue($state, (string) $previewUrl());

        return $url.(str_contains($url, '?') ? '&' : '?').'t='.time();
    }

    private static function previewUrlFromState(FileUpload $component, string $fallbackUrl): string
    {
        return self::previewUrlFromStateValue($component->getState(), $fallbackUrl);
    }

    private static function previewUrlFromStateValue(mixed $state, string $fallbackUrl): string
    {
        $path = '';

        if (is_array($state) && $state !== []) {
            $path = (string) ($state[array_key_first($state)] ?? '');
        } elseif (is_string($state)) {
            $path = $state;
        }

        $path = ltrim($path, '/');

        if ($path !== '' && TenantAssetUrl::publicDiskExists($path)) {
            return TenantAssetUrl::publicDisk($path);
        }

        return $fallbackUrl;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function iconDimensions(string $sizes): array
    {
        $parts = explode('x', strtolower($sizes));

        return [
            max(1, (int) ($parts[0] ?? 0)),
            max(1, (int) ($parts[1] ?? $parts[0] ?? 0)),
        ];
    }

    private static function currentImagePreview(string $url, string $caption): HtmlString
    {
        return new HtmlString(
            '<div class="mt-2 flex items-center gap-3">'
            .'<img src="'.e($url).'" alt="" class="h-16 w-16 rounded-lg border border-gray-200 bg-white object-contain p-1 dark:border-white/10" />'
            .'<span class="text-xs text-gray-500 dark:text-gray-400">'.e($caption).'</span>'
            .'</div>'
        );
    }

    /**
     * @return list<TextInput|Textarea>
     */
    private static function cardSchema(): array
    {
        return [
            TextInput::make('title_en')->label(__('Title (English)'))->required()->maxLength(160),
            TextInput::make('title_ar')->label(__('Title (Arabic)'))->required()->maxLength(160),
            Textarea::make('body_en')->label(__('Body (English)'))->rows(3)->required(),
            Textarea::make('body_ar')->label(__('Body (Arabic)'))->rows(3)->required(),
        ];
    }
}
