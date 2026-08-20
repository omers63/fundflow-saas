@php
    $theme = tenant()
        ? \App\Support\BrandAppearanceSettings::themeTokens()
        : \App\Support\BrandAppearanceSettings::themeTokensForHex(\App\Support\AppBrand::themeColor());
@endphp
<style>
    :root {
        --ff-theme: {{ $theme['theme'] }};
        --ff-theme-hover: {{ $theme['hover'] }};
        --ff-theme-rgb: {{ $theme['rgb'] }};
        --color-emerald-600: var(--ff-theme);
        --color-emerald-700: var(--ff-theme-hover);
    }
</style>
