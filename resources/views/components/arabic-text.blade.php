@props(['text' => null, 'fallback' => '—'])

@php
    $content = filled($text) ? (string) $text : trim((string) $slot);
@endphp

@if ($content === '')
    {{ $fallback }}
@else
    {!! \App\Support\ArabicTypography::display($content) !!}
@endif