<header {{ $attributes->class(['tenant-auth-header', 'central-auth-header']) }}>
    <a href="{{ url('/') }}" class="tenant-auth-header__brand">
        <img src="{{ \App\Support\FundflowBrand::panelLogoUrl() }}" alt="{{ config('app.name') }}"
            class="central-auth-header__logo" height="48" width="48" loading="eager" decoding="async" />
        <span class="sr-only">{{ config('app.name') }}</span>
    </a>
    <x-language-switcher />
</header>