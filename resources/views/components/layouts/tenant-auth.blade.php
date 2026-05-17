@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\AppLocale::htmlDir() }}">

<head>
    @include('partials.tenant-public-head', ['title' => $title])
</head>

<body class="tenant-auth-layout flex min-h-dvh flex-col bg-gray-50 text-gray-900 antialiased">
    <x-fund-auth-header />

    <main class="tenant-auth-layout__main flex flex-1 items-center justify-center p-4 sm:p-6">
        {{ $slot }}
    </main>

    @livewireScripts
    @include('partials.pwa-sw')
</body>
</html>
