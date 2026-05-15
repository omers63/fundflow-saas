@php
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name'));
@endphp

<nav x-data="{ open: false }"
    class="tenant-public-nav fixed top-0 left-0 right-0 z-50 border-b border-gray-100 bg-white/80 backdrop-blur-lg">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <a href="{{ route('tenant.home') }}" class="flex items-center gap-2">
                <x-fund-logo size="sm" />
                <span class="text-lg font-bold text-gray-900">{{ $fundName }}</span>
            </a>

            <div class="hidden items-center gap-4 md:flex">
                <a href="{{ route('tenant.home') }}"
                    class="text-sm font-medium text-gray-600 transition-colors hover:text-gray-900">{{ __('Home') }}</a>
                <a href="{{ route('tenant.home') }}#features"
                    class="text-sm font-medium text-gray-600 transition-colors hover:text-gray-900">{{ __('Features') }}</a>
                <a href="{{ route('tenant.home') }}#how-it-works"
                    class="text-sm font-medium text-gray-600 transition-colors hover:text-gray-900">{{ __('How it works') }}</a>
                <a href="{{ route('tenant.application.status') }}"
                    class="text-sm font-medium text-gray-600 transition-colors hover:text-gray-900">{{ __('Check status') }}</a>
                <a href="{{ route('tenant.membership') }}"
                    class="text-sm font-medium text-emerald-600 transition-colors hover:text-emerald-700">{{ __('Apply') }}</a>
                <a href="{{ route('filament.member.auth.login') }}"
                    class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-teal-700">
                    {{ __('Member login') }}
                </a>
            </div>

            <button type="button"
                class="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 md:hidden"
                @click="open = ! open" :aria-expanded="open.toString()" aria-controls="tenant-public-mobile-menu"
                aria-label="{{ __('Toggle navigation') }}">
                <svg x-show="! open" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="open" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div id="tenant-public-mobile-menu" x-show="open" x-cloak class="border-t border-gray-100 py-3 md:hidden">
            <div class="grid gap-1">
                <a href="{{ route('tenant.home') }}" @click="open = false"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Home') }}</a>
                <a href="{{ route('tenant.home') }}#features" @click="open = false"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Features') }}</a>
                <a href="{{ route('tenant.home') }}#how-it-works" @click="open = false"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('How it works') }}</a>
                <a href="{{ route('tenant.application.status') }}" @click="open = false"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">{{ __('Check status') }}</a>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <a href="{{ route('tenant.membership') }}" @click="open = false"
                    class="inline-flex items-center justify-center rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-100">{{ __('Apply') }}</a>
                <a href="{{ route('filament.member.auth.login') }}" @click="open = false"
                    class="inline-flex items-center justify-center rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">{{ __('Member login') }}</a>
            </div>
        </div>
    </div>
</nav>