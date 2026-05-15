@php
    $hasTenant = tenant() !== null;
    $appName = $hasTenant
        ? \App\Support\PublicPageSettings::fundName(tenant('name'))
        : 'FundFlow';
    $logoUrl = $hasTenant
        ? \App\Support\PublicPageSettings::fundLogoUrl()
        : \App\Support\FundflowBrand::logoUrl();
    $manifestUrl = $hasTenant ? route('tenant.manifest') : '/manifest.json';
@endphp
<meta name="theme-color" content="#059669">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ $appName }}">
<link rel="manifest" href="{{ $manifestUrl }}">
<link rel="icon" type="image/png"
    href="{{ $hasTenant && \App\Support\PublicPageSettings::hasFundLogo() ? $logoUrl : \App\Support\FundflowBrand::faviconUrl() }}">
<link rel="apple-touch-icon"
    href="{{ $hasTenant && \App\Support\PublicPageSettings::hasFundLogo() ? $logoUrl : \App\Support\FundflowBrand::logoUrl() }}">