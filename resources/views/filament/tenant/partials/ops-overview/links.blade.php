{{--
  Quick links row.
  $links: list of [label, url]
--}}
@php
    $links = $links ?? [];
    $label = $label ?? __('Quick links');
@endphp

@if ($links !== [])
    <div class="ff-ops-overview__links flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
        <span class="font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            {{ $label }}
        </span>
        @foreach ($links as $link)
            @if (filled($link['url'] ?? null))
                <a href="{{ $link['url'] }}"
                    class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ $link['label'] ?? '' }}
                </a>
            @endif
        @endforeach
    </div>
@endif
