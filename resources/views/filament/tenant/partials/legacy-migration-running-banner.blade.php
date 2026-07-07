@props(['message'])

<div
    class="flex items-center gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
    <x-filament::loading-indicator class="h-5 w-5" />
    <p>{{ $message }}</p>
</div>