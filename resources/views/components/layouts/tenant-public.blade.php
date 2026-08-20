@props([
    'title' => null,
    'metaDescription' => null,
])

@php
    $sidebar = \App\Support\PublicPageContentSettings::html('sidebar_html');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\AppLocale::htmlDir() }}">

<head>
    @include('partials.tenant-public-head', [
        'title' => $title,
        'metaDescription' => $metaDescription,
    ])
</head>

<body class="tenant-public-layout flex min-h-dvh flex-col bg-gray-50 text-gray-900 antialiased">
    @include('partials.pwa-splash')
    <x-tenant-public-nav />

    <div @class([
        'tenant-public-layout__body flex flex-1 flex-col',
        'lg:flex-row' => $sidebar !== '',
    ])>
        <main class="tenant-public-layout__main flex-1">
            {{ $slot }}
        </main>

        @if ($sidebar !== '')
            <aside class="tenant-public-sidebar w-full shrink-0 border-t border-gray-200 bg-white lg:w-72 lg:border-t-0 lg:border-s">
                <div class="tenant-public-sidebar__inner px-4 py-6 sm:px-6 lg:sticky lg:top-28">
                    {!! $sidebar !!}
                </div>
            </aside>
        @endif
    </div>

    <x-tenant-public-footer />

    @livewireScripts
    @include('partials.pwa-sw')
</body>

</html>
