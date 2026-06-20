@php
    $headerActions = $this->getCachedHeaderActions();
    $subheading = $this->getSubheading();
@endphp

@if (filled($subheading) || filled($headerActions))
    <div @class([
        'mb-5',
        'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between' => filled($headerActions),
    ])>
        @if (filled($subheading))
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $subheading }}</p>
        @endif

        @if (filled($headerActions))
            <x-filament-panels::header :actions="$headerActions" :heading="null" :subheading="null" class="!p-0" />
        @endif
    </div>
@endif

<x-filament-actions::modals />