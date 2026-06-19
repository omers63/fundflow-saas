@props([
    'href',
    'icon',
    'title',
    'subtitle' => null,
])

<a href="{{ $href }}" {{ $attributes->class(['ff-member-quick-action']) }}>
    <span class="ff-member-quick-action__icon" aria-hidden="true">{{ $icon }}</span>
    <span class="ff-member-quick-action__content">
        <span class="ff-member-quick-action__title">{{ $title }}</span>
        @if (filled($subtitle))
            <span class="ff-member-quick-action__subtitle">{{ $subtitle }}</span>
        @endif
    </span>
</a>
