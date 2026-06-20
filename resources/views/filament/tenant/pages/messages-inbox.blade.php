<x-filament-panels::page>
    <div
        class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
        <p class="font-semibold text-gray-900 dark:text-white">{{ __('Member conversations') }}</p>
        <p class="mt-1 text-xs leading-relaxed">
            {{ __('Communicate with members individually or in bulk. Opening a conversation marks their messages to you as read.') }}
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>