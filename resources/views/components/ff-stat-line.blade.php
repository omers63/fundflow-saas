@props(['text'])

<p {{ $attributes->merge(['title' => e($text)]) }}>
    {{ $slot->isEmpty() ? $text : $slot }}
</p>