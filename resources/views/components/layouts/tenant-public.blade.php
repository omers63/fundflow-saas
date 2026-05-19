@props([
    'title' => null,
    'metaDescription' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\AppLocale::htmlDir() }}">

<head>
    @include('partials.tenant-public-head', [
        'title' => $title,
        'metaDescription' => $metaDescription,
    ])
</head>

<body class="tenant-public-layout flex min-h-dvh flex-col bg-gray-50 text-gray-900 antialiased">
    <x-tenant-public-nav />

    <main class="tenant-public-layout__main flex-1">
        {{ $slot }}
    </main>

    <x-tenant-public-footer />

    @livewireScripts
    @include('partials.pwa-sw')
</body>

</html>
