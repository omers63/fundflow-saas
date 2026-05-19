@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\AppLocale::htmlDir() }}">

<head>
    @include('partials.tenant-public-head', ['title' => $title])
</head>

<body class="tenant-public-layout tenant-auth-layout flex min-h-dvh flex-col bg-gray-50 text-gray-900 antialiased">
    <x-tenant-public-nav />

    <main class="tenant-auth-layout__main flex flex-1 flex-col items-center px-4 sm:px-6">
        <div class="tenant-auth-layout__lead" aria-hidden="true"></div>
        {{ $slot }}
        <div class="tenant-auth-layout__trail" aria-hidden="true"></div>
    </main>

    <x-tenant-public-footer />

    @livewireScripts



    @include('partials.pwa-sw')
</body>
</html>
