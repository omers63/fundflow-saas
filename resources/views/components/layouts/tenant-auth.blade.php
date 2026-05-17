@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\AppLocale::htmlDir() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? \App\Support\PublicPageSettings::fundName(tenant('name')) }}</title>
    @include('partials.pwa-head')


       
                  <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&family=noto-sans-arabic:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="relative flex min-h-dvh flex-col bg-gray-50 text-gray-900 antialiased">
    <div class="absolute end-4 top-4 z-50 sm:end-6 sm:top-6">
        <x-language-switcher />
    </div>
    <main class="flex min-h-dvh flex-1 items-center justify-center p-4 sm:p-6">
        {{ $slot }}
    </main>

    @livewireScripts



    @include('partials.pwa-sw')
</body>
</html>
