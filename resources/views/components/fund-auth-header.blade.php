<header {{ $attributes->class(['tenant-auth-header', 'fund-auth-header']) }}>
    <a href="{{ route('tenant.home') }}" class="tenant-auth-header__brand">
        <x-fund-logo variant="panel" height="3rem" />
        <span class="sr-only">{{ \App\Support\PublicPageSettings::fundName(tenant('name')) }}</span>
    </a>
    <x-language-switcher />
</header>