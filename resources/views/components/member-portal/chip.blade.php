@props([
    'variant' => 'gray',
])
@php
    $variant = in_array($variant, ['green', 'amber', 'red', 'blue', 'purple', 'gray'], true) ? $variant : 'gray';
@endphp

<span {{ $attributes->class(['ff-member-chip', "ff-member-chip--{$variant}"]) }}>
    {{ $slot }}
</span>
