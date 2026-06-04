@php
    $url = $url ?? null;
    $isImage = $isImage ?? false;
@endphp

@if (filled($url))
    <div class="space-y-2">
        @if ($isImage)
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="block">
                <img src="{{ $url }}" alt="{{ __('Receipt') }}"
                    class="max-h-40 w-full rounded-lg border border-gray-200 object-contain dark:border-gray-700" />
            </a>
        @else
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                class="text-sm font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400">
                {{ __('Download attachment') }}
            </a>
        @endif
    </div>
@endif