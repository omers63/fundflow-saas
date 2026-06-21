@php
    $languageSwitch = \BezhanSalleh\LanguageSwitch\LanguageSwitch::make();
    $locales = $languageSwitch->getLocales();
    $currentLocale = app()->getLocale();
@endphp

@if (count($locales) > 1)
    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false"
        :class="{ 'language-switcher--open': open }" {{ $attributes->class(['language-switcher']) }}>
        <button type="button" class="language-switch-trigger" @click="open = ! open" :aria-expanded="open.toString()"
            aria-haspopup="true" aria-controls="language-switch-menu"
            aria-label="{{ __('Change language') }}: {{ $languageSwitch->getLabel($currentLocale) }}">
            <img src="{{ $languageSwitch->getFlag($currentLocale) }}" alt="" class="language-switch-trigger__flag"
                width="16" height="16" loading="lazy" decoding="async" />
            <span
                class="language-switch-trigger__label hidden sm:inline">{{ $languageSwitch->getLabel($currentLocale) }}</span>
        </button>

        <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95" id="language-switch-menu" class="language-switch-menu" role="menu">
            @foreach ($locales as $locale)
                @if (!app()->isLocale($locale))
                    <a href="{{ \App\Support\LocaleSwitchUrl::for($locale) }}" role="menuitem" class="language-switch-option"
                        @click="open = false">
                        <img src="{{ $languageSwitch->getFlag($locale) }}" alt="" class="language-switch-option__flag" width="16"
                            height="16" loading="lazy" decoding="async" />
                        <span class="language-switch-option__label">{{ $languageSwitch->getLabel($locale) }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    </div>
@endif