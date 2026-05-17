@props([
    'wireKey' => 'language-switch-inline',
])
@php
    $switch = \BezhanSalleh\LanguageSwitch\LanguageSwitch::make();
@endphp
@if (count($switch->getLocales()) > 1)
    <div {{ $attributes->class(['language-switcher']) }}>
            <livewire:language-switch-component :key="$wireKey" />
        </div>
@endif
