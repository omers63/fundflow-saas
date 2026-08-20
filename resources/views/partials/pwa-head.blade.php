@php
    $hasTenant = tenant() !== null;
    $appName = $hasTenant
        ? \App\Support\PublicPageSettings::fundName(tenant('name'))
        : \App\Support\AppBrand::name();
    $themeColor = $hasTenant
        ? \App\Support\BrandAppearanceSettings::themeColor()
        : \App\Support\AppBrand::themeColor();
    $faviconUrl = $hasTenant
        ? \App\Support\BrandAppearanceSettings::faviconUrl()
        : \App\Support\AppBrand::iconUrl('favicon_32');
    $appleTouchUrl = $hasTenant
        ? \App\Support\BrandAppearanceSettings::appleTouchIconUrl()
        : \App\Support\AppBrand::iconUrl('apple_touch');
    $favicon16Url = $hasTenant
        ? \App\Support\BrandAppearanceSettings::iconUrl('favicon_16')
        : \App\Support\AppBrand::iconUrl('favicon_16');
    $manifestUrl = $hasTenant ? route('tenant.manifest') : url('/manifest.json');
@endphp
@include('partials.tenant-theme')
<meta name="theme-color" content="{{ $themeColor }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ $appName }}">
<link rel="manifest" href="{{ $manifestUrl }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $favicon16Url }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $faviconUrl }}">
<link rel="apple-touch-icon" href="{{ $appleTouchUrl }}">
@foreach (\App\Support\AppBrand::splashStartupImages() as $splash)
<link rel="apple-touch-startup-image" media="{{ $splash['media'] }}" href="{{ $splash['url'] }}">
@endforeach