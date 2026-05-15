@props([
    'size' => 'md',
    'variant' => 'default',
])

@php
    use App\Support\FundflowBrand;

    $logoUrl = \App\Support\PublicPageSettings::fundLogoUrl();
    $fallbackUrl = FundflowBrand::logoUrl();
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name') ?? null);
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
