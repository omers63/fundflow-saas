@php
    /** @var array{label: string, path: string, exists: bool, readable: bool, size_bytes: int, size_label: string, truncated: bool, content: string} $payload */
@endphp
<div class="space-y-3">
    <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
        <p class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $payload['path'] }}</p>
        <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $payload['size_label'] }}</p>
        @if ($payload['truncated'])
            <p class="text-xs text-amber-700 dark:text-amber-300">
                {{ __('Showing the end of this file (last ~64 KB).') }}
            </p>
        @endif
    </div>

    @if (! $payload['exists'])
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Log file not found.') }}</p>
    @elseif (! $payload['readable'])
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Unable to read this log file.') }}</p>
    @elseif ($payload['size_bytes'] === 0)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Log file is empty.') }}</p>
    @else
        <pre
            class="max-h-[min(70vh,32rem)] overflow-auto rounded-lg border border-gray-200 bg-gray-950 p-3 font-mono text-[11px] leading-relaxed text-gray-100 dark:border-white/10">{{ $payload['content'] }}</pre>
    @endif
</div>
