@php
    use BezhanSalleh\LanguageSwitch\LanguageSwitch;

    $languageSwitch = LanguageSwitch::make();
    $locales = $languageSwitch->getLocales();
    $currentLocale = app()->getLocale();
    $placement = __('filament-panels::layout.direction') === 'rtl' ? 'bottom-start' : 'bottom-end';
@endphp

<x-filament::dropdown teleport :placement="$placement" :flip="false" :shift="true" width="w-[9.5rem]"
    class="fi-dropdown language-switch-dropdown ff-portal-topbar-language-switch" data-nosnippet="true">
    <x-slot name="trigger">
        <div class="ff-portal-topbar-chip inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold leading-none text-gray-700 dark:text-gray-200"
            aria-label="{{ __('Change language') }}: {{ $languageSwitch->getLabel($currentLocale) }}">
            <x-language-switch::flag :src="$languageSwitch->getFlag($currentLocale)" :circular="true"
                :alt="$languageSwitch->getLabel($currentLocale)" :switch="true"
                class="!h-4 !w-4 !max-h-4 !max-w-4 shrink-0 rounded-full" />
            <span class="hidden whitespace-nowrap sm:inline">{{ $languageSwitch->getLabel($currentLocale) }}</span>
        </div>
    </x-slot>

    <x-filament::dropdown.list class="ff-portal-topbar-language-switch__list !border-t-0 !p-1">
        @foreach ($locales as $locale)
            @if (!app()->isLocale($locale))
                <button type="button" wire:click.stop="switchLocale('{{ $locale }}')"
                    class="ff-portal-topbar-language-switch__option flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-xs font-semibold text-gray-700 outline-none transition-colors hover:bg-gray-950/5 focus:bg-gray-950/5 dark:text-gray-200 dark:hover:bg-white/5 dark:focus:bg-white/5 rtl:flex-row-reverse">
                    <x-language-switch::flag :src="$languageSwitch->getFlag($locale)" :circular="true"
                        :alt="$languageSwitch->getLabel($locale)" class="!h-4 !w-4 shrink-0 rounded-full" />
                    <span class="min-w-0 flex-1 truncate text-start">{{ $languageSwitch->getLabel($locale) }}</span>
                </button>
            @endif
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>