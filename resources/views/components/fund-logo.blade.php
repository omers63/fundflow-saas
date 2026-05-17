@props([
    'size' => 'md',
    'variant' => 'framed',
    'height' => null,
])

@php
    use App\Support\FundflowBrand;
    use App\Support\PublicPageSettings;

    $panelHeight = $height ?? PublicPageSettings::BRAND_LOGO_HEIGHT;
    $fundName = PublicPageSettings::fundName(tenant('name') ?? null);

    if ($variant === 'panel') {
        $logoUrl = PublicPageSettings::fundPanelBrandLogoUrl();
        $fallbackUrl = FundflowBrand::panelLogoUrl();
    } else {
        $logoUrl = PublicPageSettings::fundLogoUrl();
        $fallbackUrl = FundflowBrand::logoUrl();
    }

    $frameClasses = match ($size) {
        'sm' => 'h-10 w-10',
        'lg' => 'h-14 w-14',
        default => 'h-12 w-12',
    };
    $imageClasses = match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-11 w-11',
        default => 'h-10 w-10',
    };
    $variantClasses = match ($variant) {
        'on-dark' => 'border-white/15 bg-white/10 shadow-none',
        default => 'border-gray-200 bg-gray-50 shadow-sm',
    };
@endphp

@if ($variant === 'panel')
    <img
        {{ $attributes->class(['tenant-public-brand-logo object-contain']) }}
        src="{{ $logoUrl }}"
        alt="{{ $fundName }}"
        style="height: {{ $panelHeight }};"
        loading="eager"
        decoding="async"
        data-fund-logo-fallback="{{ $fallbackUrl }}"
        onerror="if (this.dataset.fundLogoFallback) { this.src = this.dataset.fundLogoFallback; }"
    />
@else
    <div {{ $attributes->class(['flex shrink-0 items-center justify-center overflow-hidden rounded-xl border', $frameClasses, $variantClasses]) }}>
        <img
            src="{{ $logoUrl }}"
            alt=""
            role="presentation"
            class="{{ $imageClasses }} object-contain"
            width="40"
            height="40"
            loading="eager"
            decoding="async"
            data-fund-logo-fallback="{{ $fallbackUrl }}"
            onerror="if (this.dataset.fundLogoFallback) { this.src = this.dataset.fundLogoFallback; }"
        />
        <span class="sr-only">{{ $fundName }}</span>
    </div>
@endif
