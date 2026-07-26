@props([
    'section',
    'unfolded' => false,
    'title',
    'hint' => null,
])

<div
    {{ $attributes->class(['ff-lazy-fold overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-800']) }}
    wire:key="ff-lazy-fold-{{ $section }}"
>
    @if ($unfolded)
        <div class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $title }}</p>
        </div>
        <div class="p-4">
            {{ $slot }}
        </div>
    @else
        <button
            type="button"
            wire:click="unfoldSection('{{ $section }}')"
            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start transition hover:bg-gray-50 dark:hover:bg-white/5"
        >
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $title }}</p>
                @if (filled($hint))
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $hint }}</p>
                @endif
            </div>
            <span class="shrink-0 text-sm font-semibold text-sky-600 dark:text-sky-400">{{ __('Expand') }}</span>
        </button>
    @endif
</div>
