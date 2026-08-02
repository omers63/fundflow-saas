@props([
    'tone' => 'info', // info|success|warning|danger
    'title' => null,
    'body' => null,
    /** @var list<string>|null */
    'items' => null,
])

@php
    $toneClass = match ($tone) {
        'success' => 'ff-freeze-callout--success',
        'warning' => 'ff-freeze-callout--warning',
        'danger' => 'ff-freeze-callout--danger',
        default => 'ff-freeze-callout--info',
    };
@endphp

<div {{ $attributes->class(['ff-freeze-callout', $toneClass]) }}>
@if (filled($title))
    <p class="ff-freeze-callout__title">{{ $title }}</p>
@endif

@if (filled($body))
    <p class="ff-freeze-callout__body">{!! $body !!}</p>
@endif

@if (is_array($items) && $items !== [])
    <ul class="ff-freeze-callout__list">
    @foreach ($items as $item)
        <li>{!! $item !!}</li>
    @endforeach
        </ul>
@endif

@if (isset($slot) && trim((string) $slot) !== '')
    {{ $slot }}
@endif
</div>
