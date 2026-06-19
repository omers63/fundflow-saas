@props([
    'tone' => 'blue',
    'title' => null,
])

@php
    $tone = in_array($tone, ['amber', 'blue', 'green', 'red'], true) ? $tone : 'blue';
@endphp

<div
    {{ $attributes->class(['ff-member-notice', "ff-member-notice--{$tone}"]) }}
    role="status"
>
    @if (filled($title))
        <p class="ff-member-notice__title">{{ $title }}</p>
    @endif
    <div class="ff-member-notice__body">{{ $slot }}</div>
</div>
