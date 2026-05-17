@php
    $pageTitle = $title ?? \App\Support\PublicPageSettings::fundName(tenant('name'));
    $metaDescription = $metaDescription ?? __('A transparent family fund platform for membership, contributions, and interest-free loans.');
@endphp

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{{ $metaDescription }}">
<title>{{ $pageTitle }}</title>
@include('partials.pwa-head')

<link rel="preconnect" href="https://fonts.bunny.net">
<link
    href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&family=noto-sans-arabic:400,500,600,700&display=swap"
    rel="stylesheet"
/>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles

<style>
    [x-cloak] {
        display: none !important;
    }
</style>
