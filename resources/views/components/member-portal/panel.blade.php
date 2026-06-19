@props([
    'title' => null,
    'link' => null,
    'linkLabel' => null,
])

<div {{ $attributes->class(['ff-member-panel']) }}>
    @if (filled($title) || filled($link))
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
