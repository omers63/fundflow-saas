@php
    $resolvedRenderHook = $languageSwitch->getRenderHook();
    $shouldTeleport = ! str_contains($resolvedRenderHook, '::sidebar.')
        && ! str_contains($resolvedRenderHook, 'user-menu.');
@endphp

<x-filament::dropdown
    :teleport="$shouldTeleport"
    :placement="$placement"
    :flip="false"
    :shift="true"
    :width="$isFlagsOnly ? 'w-fit fls-flag-only-width' : 'w-fit fls-dropdown-width'"
    :max-height="$maxHeight"
    class="fi-dropdown fi-user-menu language-switch-dropdown"
    data-nosnippet="true"
>
    <x-slot name="trigger">
        <div
            @class([
                'language-switch-trigger flex items-center gap-1.5 px-2 text-primary-600 bg-primary-500/10',
                'h-11 min-w-11' => $isFlagsOnly,
                'h-11 min-h-11' => ! $isFlagsOnly,
                'rounded-full' => $isCircular,
                'rounded-lg' => ! $isCircular,
                'ring-2 ring-inset ring-gray-200 hover:ring-gray-300 dark:ring-gray-500 hover:dark:ring-gray-400' => $isFlagsOnly || $hasFlags,
            ])
            @if ($isFlagsOnly)
                x-tooltip="{
                    content: @js($languageSwitch->getLabel(app()->getLocale())),
                    theme: $store.theme,
                    placement: document.dir === 'rtl' ? 'left' : 'right',
                }"
            @endif
        >
            @if ($isFlagsOnly || $hasFlags)
                <x-language-switch::flag
                    :src="$languageSwitch->getFlag(app()->getLocale())"
                    :circular="$isCircular"
                    :alt="$languageSwitch->getLabel(app()->getLocale())"
                    :switch="true"
                />
            @else
                <span class="font-semibold text-md">{{ $languageSwitch->getCharAvatar(app()->getLocale()) }}</span>
            @endif
            @unless ($isFlagsOnly)
                <span class="max-w-[5.5rem] truncate text-xs font-semibold text-gray-700 dark:text-gray-200">
                    {{ $languageSwitch->getLabel(app()->getLocale()) }}
                </span>
            @endunless
        </div>
    </x-slot>

    <x-filament::dropdown.list @class(['language-switch-dropdown__list !border-t-0 !p-1.5 min-w-[9rem]'])>
        @foreach ($locales as $locale)
            @if (! app()->isLocale($locale))
                <button
                    type="button"
                    wire:click="changeLocale('{{ $locale }}')"
                    @class([
                        'flex w-full items-center rounded-md p-1.5 outline-none transition-colors duration-75 fi-dropdown-list-item whitespace-nowrap disabled:pointer-events-none disabled:opacity-70 fi-dropdown-list-item-color-gray hover:bg-gray-950/5 focus:bg-gray-950/5 dark:hover:bg-white/5 dark:focus:bg-white/5',
                        'justify-center px-2 py-0.5' => $isFlagsOnly,
                        'justify-start gap-2 rtl:flex-row-reverse' => ! $isFlagsOnly,
                    ])
                >
                    @if ($hasFlags)
                        <x-language-switch::flag
                            :src="$languageSwitch->getFlag($locale)"
                            :circular="$isCircular"
                            :alt="$languageSwitch->getLabel($locale)"
                            class="h-7 w-7 shrink-0"
                        />
                    @else
                        <span
                            @class([
                                'flex h-7 w-7 shrink-0 items-center justify-center p-2 text-xs font-semibold bg-primary-500/10 text-primary-600 group-hover:border group-hover:border-primary-500/10 group-hover:bg-white group-hover:text-primary-600 group-focus:text-white',
                                'rounded-full' => $isCircular,
                                'rounded-lg' => ! $isCircular,
                            ])
                        >
                            {{ $languageSwitch->getCharAvatar($locale) }}
                        </span>
                    @endif
                    @unless ($isFlagsOnly)
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-200">
                            {{ $languageSwitch->getLabel($locale) }}
                        </span>
                    @endunless
                </button>
            @endif
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
