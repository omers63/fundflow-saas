@props([
    'percent' => 0,
    'tone' => 'success',
])

@php
    $percent = max(0, min(100, (int) $percent));
    $tone = in_array($tone, ['success', 'warning', 'danger', 'primary'], true) ? $tone : 'success';
@endphp

<div {{ $attributes->class(['ff-member-progress']) }} role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
    <div class="ff-member-progress__track">
        <div
            class="ff-member-progress__fill ff-member-progress__fill--{{ $tone }}"
            style="width: {{ $percent }}%"
        ></div>
    </div>
</div>
