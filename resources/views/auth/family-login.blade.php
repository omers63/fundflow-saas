<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4f46e5">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-192.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-192.svg">
    <title>{{ __('Family Login') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-slate-950 text-white">
    <main class="mx-auto max-w-md px-6 py-16">
        <h1 class="mb-2 text-2xl font-bold">{{ __('Login to') }} {{ $family->name }}</h1>
        <p class="mb-8 text-slate-300">{{ __('Use your family account credentials.') }}</p>

        <form method="POST" action="{{ route('family.login.submit', $family->slug) }}" class="space-y-4">
            @csrf
            <input type="email" name="email" required class="w-full rounded border border-slate-700 bg-slate-900 p-3"
                placeholder="{{ __('Email') }}">
            <input type="password" name="password" required
                class="w-full rounded border border-slate-700 bg-slate-900 p-3" placeholder="{{ __('Password') }}">
            @error('email')
                <div class="text-red-400">{{ $message }}</div>
            @enderror
            <button class="w-full rounded bg-indigo-600 px-4 py-3">{{ __('Login') }}</button>
        </form>
    </main>
</body>

</html>