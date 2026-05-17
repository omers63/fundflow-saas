@props([
    'wireKey' => 'language-switch-inline',
])

@php
    $languageSwitch = \BezhanSalleh\LanguageSwitch\LanguageSwitch::make();
    $locales = $languageSwitch->getLocales();
    $currentLocale = app()->getLocale();
@endphp

@if (count($locales) > 1)
    <div
        x-data="{ open: false }"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        :class="{ 'language-switcher--open': open }"
        {{ $attributes->class(['language-switcher']) }}
    >
        <button
            type="button"
            class="language-switch-trigger"
            data-language-tooltip="{{ $languageSwitch->getLabel($currentLocale) }}"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="true"
            aria-label="{{ $languageSwitch->getLabel($currentLocale) }}"
        >
            <img
                src="{{ $languageSwitch->getFlag($currentLocale) }}"
                alt=""
                class="language-switch-trigger__flag"
                width="28"
                height="28"
            />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="language-switch-menu"
            role="menu"
        >
            @foreach ($locales as $locale)
                @if (! app()->isLocale($locale))
                    <a
                        href="{{ route('tenant.locale.switch', ['locale' => $locale]) }}"
                        role="menuitem"
                        class="language-switch-option"
                        data-language-tooltip="{{ $languageSwitch->getLabel($locale) }}"
                        @click="open = false"
                    >
                        <img
                            src="{{ $languageSwitch->getFlag($locale) }}"
                            alt="{{ $languageSwitch->getLabel($locale) }}"
                            class="language-switch-option__flag"
                            width="28"
                            height="28"
                        />
                    </a>
                @endif
            @endforeach
        </div>
    </div>
@endif
