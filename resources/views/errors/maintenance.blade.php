<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4f46e5">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon-192.svg" type="image/svg+xml">
    <title>Maintenance</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-950 text-white">
    <main class="mx-auto flex min-h-screen w-full max-w-lg items-center px-6 py-16">
        <div class="w-full rounded-2xl border border-slate-800 bg-slate-900 p-6 text-center">
            <p class="text-sm uppercase tracking-wide text-slate-400">FundFlow</p>
            <h1 class="mt-2 text-2xl font-semibold">Maintenance Mode</h1>
            <p class="mt-4 text-slate-300">{{ $message }}</p>
        </div>
    </main>
</body>

</html>