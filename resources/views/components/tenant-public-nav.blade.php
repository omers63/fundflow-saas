@php
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name'));
    $isHome = request()->routeIs('tenant.home');
    $isAuthPage = \App\Support\ShowsFundPublicShell::onTenantFilamentAuthPage();
    $content = \App\Support\PublicPageContentSettings::class;
    $headerBanner = $content::html('header_banner');
@endphp

<nav x-data="{ open: false }" @class([
    'tenant-public-nav fixed inset-x-0 top-0 z-50 bg-white/95 shadow-xs ring-1 ring-gray-950/5 backdrop-blur-lg',
    'tenant-public-nav--auth' => $isAuthPage,
])
    aria-label="{{ __('Site navigation') }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="tenant-public-nav__bar flex min-h-16 items-center gap-2 py-2 sm:gap-3">
            <a href="{{ route('tenant.home') }}"
                class="tenant-public-nav__brand flex min-w-0 shrink items-center gap-2 overflow-hidden sm:gap-3">
                <x-fund-logo variant="panel" class="shrink-0" />
                <span class="tenant-public-nav__fund-name truncate text-base font-bold text-gray-900 sm:text-lg">
                    {{ $fundName }}
                </span>
            </a>

            <div class="tenant-public-nav__end ms-auto flex min-w-0 items-center gap-1.5 sm:gap-2">
                <div class="tenant-public-nav__menu min-w-0 items-center gap-1 md:overflow-x-auto">
                    <a href="{{ route('tenant.home') }}" @class([
                        'tenant-public-nav__badge',
                        'tenant-public-nav__badge--current' => $isHome,
                    ]) @if ($isHome) aria-current="page"
@endif>{{ $content::text('nav_home') }}</a>
                    @if ($content::enabled('nav_show_features'))
                        <a href="{{ route('tenant.home') }}#features"
                            class="tenant-public-nav__badge">{{ $content::text('nav_features') }}</a>
                    @endif
                    @if ($content::enabled('nav_show_how_it_works'))
                        <a href="{{ route('tenant.home') }}#how-it-works"
                            class="tenant-public-nav__badge">{{ $content::text('nav_how_it_works') }}</a>
                    @endif
                    @if ($content::enabled('nav_show_check_status'))
                        <a href="{{ route('tenant.application.status') }}"
                            class="tenant-public-nav__badge">{{ $content::text('nav_check_status') }}</a>
                    @endif
                    <a href="{{ route('filament.member.auth.login') }}"
                        class="tenant-public-nav__badge tenant-public-nav__badge--emphasis">{{ $content::text('nav_member_login') }}</a>
                    <a href="{{ route('tenant.membership') }}"
                        class="tenant-public-nav__badge tenant-public-nav__badge--primary">{{ $content::text('nav_apply') }}</a>
                </div>

                <button type="button"
                    class="tenant-public-nav__badge tenant-public-nav__badge--icon tenant-public-nav__menu-toggle shrink-0"
                    @click="open = ! open" :aria-expanded="open.toString()" aria-controls="tenant-public-mobile-menu"
                    aria-label="{{ __('Toggle navigation') }}">
                    <svg x-show="! open" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg x-show="open" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <x-language-switcher class="tenant-public-nav__language shrink-0" />
            </div>
        </div>

        <div id="tenant-public-mobile-menu" x-show="open" x-cloak @click.outside="open = false"
            class="tenant-public-nav__mobile-menu border-t border-gray-100 py-3">
            <div class="grid gap-2">
                <a href="{{ route('tenant.home') }}" @click="open = false" @class([
                    'tenant-public-nav__badge',
                    'tenant-public-nav__badge--current' => $isHome,
                ]) @if ($isHome) aria-current="page"
@endif>{{ $content::text('nav_home') }}</a>
                @if ($content::enabled('nav_show_features'))
                    <a href="{{ route('tenant.home') }}#features" @click="open = false"
                        class="tenant-public-nav__badge">{{ $content::text('nav_features') }}</a>
                @endif
                @if ($content::enabled('nav_show_how_it_works'))
                    <a href="{{ route('tenant.home') }}#how-it-works" @click="open = false"
                        class="tenant-public-nav__badge">{{ $content::text('nav_how_it_works') }}</a>
                @endif
                @if ($content::enabled('nav_show_check_status'))
                    <a href="{{ route('tenant.application.status') }}" @click="open = false"
                        class="tenant-public-nav__badge">{{ $content::text('nav_check_status') }}</a>
                @endif
            </div>
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <a href="{{ route('tenant.membership') }}" @click="open = false"
                    class="tenant-public-nav__badge tenant-public-nav__badge--primary">{{ $content::text('nav_apply') }}</a>
                <a href="{{ route('filament.member.auth.login') }}" @click="open = false"
                    class="tenant-public-nav__badge tenant-public-nav__badge--emphasis">{{ $content::text('nav_member_login') }}</a>
            </div>
        </div>
    </div>
</nav>

@if ($headerBanner !== '')
    <div class="tenant-public-header-banner">
        <div class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
            {!! $headerBanner !!}
        </div>
    </div>
@endif

@if ($headerBanner !== '')
    <div class="tenant-public-header-banner">
        <div class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
            {!! $headerBanner !!}
        </div>
    </div>
@endif
