@props([
    'wireModel',
    'label',
    'description' => null,
    'summary' => null,
    'sampleUrl' => null,
    'sampleLabel' => null,
    'disabled' => false,
    'disabledHint' => null,
    'icon' => 'heroicon-o-document-text',
])

@php
    $hasFile = filled($summary);
    $rowCount = (int) ($summary['row_count'] ?? 0);
    $updatedAt = $hasFile
        ? \Illuminate\Support\Carbon::parse($summary['modified_at'] ?? now())->diffForHumans()
        : null;
@endphp

<div @class([
    'rounded-xl border p-4 transition',
    'border-emerald-300 bg-emerald-50/60 dark:border-emerald-500/40 dark:bg-emerald-500/10' => $hasFile,
    'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900/40' => ! $hasFile,
    'opacity-60' => $disabled,
])>
    <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            <span @class([
                'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' => $hasFile,
                'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => ! $hasFile,
            ])>
                <x-filament::icon :icon="$hasFile ? 'heroicon-o-check-circle' : $icon" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</p>
                @if ($description)
                    <p class="mt-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $description }}</p>
                @endif
            </div>
        </div>

        <span @class([
            'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium',
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $hasFile,
            'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => ! $hasFile,
        ])>
            {{ $hasFile ? __('Ready') : __('Needed') }}
        </span>
    </div>

    @if ($hasFile)
        <p class="mt-3 text-xs font-medium text-emerald-700 dark:text-emerald-300">
            {{ __(':count rows ready', ['count' => number_format($rowCount)]) }}
            <span class="font-normal text-emerald-600/80 dark:text-emerald-300/70">· {{ __('uploaded :time', ['time' => $updatedAt]) }}</span>
        </p>
    @endif

    <div class="mt-3">
        <label class="relative flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-4 text-center transition hover:border-sky-400 hover:bg-sky-50 dark:border-white/15 dark:bg-gray-900/60 dark:hover:border-sky-500/60 dark:hover:bg-sky-500/5 aria-disabled:cursor-not-allowed">
            <input
                type="file"
                accept=".csv,text/csv,text/plain,application/csv"
                @disabled($disabled)
                wire:model.live="{{ $wireModel }}"
                class="absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
            />
            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ $hasFile ? __('Replace file') : __('Choose a CSV file') }}
            </span>
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('CSV up to 50 MB') }}</span>
        </label>
    </div>

    <div wire:loading wire:target="{{ $wireModel }}" class="mt-2 flex items-center gap-2 text-sm text-sky-600 dark:text-sky-400">
        <x-filament::loading-indicator class="h-4 w-4" />
        <span>{{ __('Uploading…') }}</span>
    </div>

    @if ($disabled && $disabledHint)
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ $disabledHint }}</p>
    @endif

    @if ($sampleUrl)
        <p class="mt-2 text-xs">
            <a href="{{ $sampleUrl }}" target="_blank" rel="noopener"
                class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400">
                {{ $sampleLabel ?? __('Download sample CSV') }}
            </a>
        </p>
    @endif
</div>
