<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4f46e5">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-192.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/icon-192.svg">
    <title>{{ $family->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-slate-100 text-slate-900">
    <main class="mx-auto max-w-4xl px-6 py-12">
        <h1 class="text-3xl font-semibold">{{ $family->name }}</h1>
        <p class="mt-2 text-slate-600">{{ __('Family code') }}: {{ $family->family_code }}</p>
        <a class="mt-4 inline-block rounded bg-slate-900 px-4 py-2 text-white"
            href="{{ route('family.login', $family->slug) }}">{{ __('Member/Admin Login') }}</a>

        @if (session('success'))
            <div class="mt-6 rounded border border-emerald-300 bg-emerald-50 p-4 text-emerald-900">{{ session('success') }}
            </div>
        @endif

        <section class="mt-8 rounded-xl bg-white p-6 shadow">
            <h2 class="mb-4 text-xl font-semibold">{{ __('Enrollment Workflow') }}</h2>
            <form method="POST" action="{{ route('public.enroll', $family->slug) }}" class="space-y-4">
                @csrf
                <input name="applicant_name" required class="w-full rounded border p-3"
                    placeholder="{{ __('Applicant Name') }}">
                <input name="email" type="email" required class="w-full rounded border p-3"
                    placeholder="{{ __('Email') }}">
                <input name="phone" class="w-full rounded border p-3" placeholder="{{ __('Phone') }}">
                <textarea name="notes" class="w-full rounded border p-3" rows="4"
                    placeholder="{{ __('Notes') }}"></textarea>
                <button class="rounded bg-indigo-600 px-5 py-2 text-white">{{ __('Submit Enrollment') }}</button>
            </form>
        </section>
    </main>
</body>

</html>