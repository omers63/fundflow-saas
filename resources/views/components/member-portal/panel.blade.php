@props([
    'title' => null,
    'link' => null,
    'linkLabel' => null,
    'collapsible' => false,
    'open' => true,
])

@php
    $hasHead = filled($title) || filled($link) || isset($summary);
@endphp

@if ($collapsible)
    <details
        {{ $attributes->class(['ff-member-panel', 'ff-member-panel--collapsible', 'group']) }}
        @if ($open) open @endif
    >
        @if ($hasHead)
            <summary class="ff-member-panel__head ff-member-panel__summary">
                <span class="ff-member-panel__summary-main">
                    @if (filled($title))
                        <span class="ff-member-panel__title">{{ $title }}</span>
                    @endif
                    @isset($summary)
                        <span class="ff-member-panel__summary-extra">{{ $summary }}</span>
                    @endisset
                </span>
                <span class="ff-member-panel__summary-aside">
                    @if (filled($link))
                        <a
                            href="{{ $link }}"
                            wire:navigate
                            class="ff-member-panel__link"
                            @click.stop
                        >
                            {{ $linkLabel ?? __('View all') }}
                        </a>
                    @endif
                    <x-heroicon-o-chevron-down class="ff-member-panel__chevron h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180" />
                </span>
            </summary>
        @endif
        <div class="ff-member-panel__body">
            {{ $slot }}
        </div>
    </details>
@else
    <div {{ $attributes->class(['ff-member-panel']) }}>
        @if ($hasHead)
            <div class="ff-member-panel__head">
                @if (filled($title))
                    <span class="ff-member-panel__title">{{ $title }}</span>
                @endif
                @if (filled($link))
                    <a href="{{ $link }}" class="ff-member-panel__link">
                        {{ $linkLabel ?? __('View all') }}
                    </a>
                @endif
            </div>
        @endif
        <div class="ff-member-panel__body">
            {{ $slot }}
        </div>
    </div>
@endif
