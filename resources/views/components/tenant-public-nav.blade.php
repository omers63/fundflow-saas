@php
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name'));
@endphp

<nav x-data="{ open: false }"
    class="tenant-public-nav fixed inset-x-0 top-0 z-50 bg-white/95 shadow-xs ring-1 ring-gray-950/5 backdrop-blur-lg"
    aria-label="{{ __('Site navigation') }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="tenant-public-nav__bar flex min-h-16 items-center justify-between gap-3 py-2">
            <div class="tenant-public-nav__start flex min-w-0 flex-1 items-center gap-2 sm:gap-3 md:flex-none">
                <a href="{{ route('tenant.home') }}" class="tenant-public-nav__brand flex min-w-0 items-center gap-3">
                    <x-fund-logo variant="panel" class="shrink-0" />
                    <span class="tenant-public-nav__fund-name truncate text-base font-bold text-gray-900 sm:text-lg">
                        {{ $fundName }}
                    </span>
                </a>
                <x-language-switcher class="tenant-public-nav__language shrink-0" />
            </div>

            <div class="tenant-public-nav__menu hidden items-center gap-2 md:flex">
                <a href="{{ route('tenant.home') }}" class="tenant-public-nav__badge">{{ __('Home') }}</a>
                <a href="{{ route('tenant.home') }}#features" class="tenant-public-nav__badge">{{ __('Features') }}</a>
                <a href="{{ route('tenant.home') }}#how-it-works"
                    class="tenant-public-nav__badge">{{ __('How it works') }}</a>
                <a href="{{ route('tenant.application.status') }}"
                    class="tenant-public-nav__badge">{{ __('Check application status') }}</a>
                <a href="{{ route('filament.member.auth.login') }}"
                    class="tenant-public-nav__badge tenant-public-nav__badge--emphasis">{{ __('Member login') }}</a>
                <a href="{{ route('tenant.membership') }}"
                    class="tenant-public-nav__badge tenant-public-nav__badge--primary">{{ __('Apply for membership') }}</a>
            </div>

            <div class="flex items-center gap-2 md:hidden">
                <button type="button" class="tenant-public-nav__badge inline-flex !p-2" @click="open = ! open"
                    :aria-expanded="open.toString()" aria-controls="tenant-public-mobile-menu"
                    aria-label="{{ __('Toggle navigation') }}">
                    <svg x-show="! open" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg x-show="open" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <div id="tenant-public-mobile-menu" x-show="open" x-cloak
            class="tenant-public-nav__mobile-menu border-t border-gray-100 py-3 md:hidden">
            <div class="grid gap-2">
                <a href="{{ route('tenant.home') }}" @click="open = false"
                    class="tenant-public-nav__badge">{{ __('Home') }}</a>
                <a href="{{ route('tenant.home') }}#features" @click="open = false"
                    class="tenant-public-nav__badge">{{ __('Features') }}</a>
                <a href="{{ route('tenant.home') }}#how-it-works" @click="open = false"
                    class="tenant-public-nav__badge">{{ __('How it works') }}</a>
                <a href="{{ route('tenant.application.status') }}" @click="open = false"
                    class="tenant-public-nav__badge">{{ __('Check application status') }}</a>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <a href="{{ route('tenant.membership') }}" @click="open = false"
                    class="tenant-public-nav__badge tenant-public-nav__badge--primary">{{ __('Apply for membership') }}</a>
                <a href="{{ route('filament.member.auth.login') }}" @click="open = false"
                    class="tenant-public-nav__badge tenant-public-nav__badge--emphasis">{{ __('Member login') }}</a>
            </div>
        </div>
    </div>
</nav>