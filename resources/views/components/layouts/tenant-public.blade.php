@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Support\PublicPageSettings::fundName(tenant('name')) }}</title>
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="flex min-h-screen flex-col bg-gray-50 text-gray-900 antialiased">
    <x-tenant-public-nav />

    <main class="flex-1 pt-24 pb-16">
        {{ $slot }}
    </main>

    <x-tenant-public-footer />

    @livewireScripts
    @include('partials.pwa-sw')
</body>

</html>
