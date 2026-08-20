@php
use App\Support\PublicPageContentSettings;
use App\Support\PublicPageSettings;

$fundName = PublicPageSettings::fundName(tenant('name'));
$termsDownloadUrl = PublicPageSettings::termsAndConditionsDownloadUrl();
$contactEmail = PublicPageSettings::contactEmail();
$contactPhone = PublicPageSettings::contactPhone();
$footerExtra = PublicPageContentSettings::html('footer_extra');
@endphp

<footer class="tenant-public-footer mt-auto" aria-label="{{ __('Site footer') }}">
    <div class="tenant-public-footer__inner mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="tenant-public-footer__grid">
            <div class="tenant-public-footer__brand">
                <a href="{{ route('tenant.home') }}" class="tenant-public-footer__brand-link">
                    <x-fund-logo variant="panel" class="tenant-public-footer__logo shrink-0" />
                    <span class="tenant-public-footer__fund-name">{{ $fundName }}</span>
                </a>
                <p class="tenant-public-footer__tagline">
                    {{ PublicPageContentSettings::text('footer_tagline') }}
                </p>
                @if ($footerExtra !== '')
                    <div class="tenant-public-footer__extra">
                        {!! $footerExtra !!}
                    </div>
                @endif
            </div>

            <div class="tenant-public-footer__column">
                <h2 class="tenant-public-footer__heading">{{ PublicPageContentSettings::text('footer_links_heading') }}</h2>
                <ul class="tenant-public-footer__links">
                    <li>
                        <a href="{{ route('tenant.home') }}">{{ PublicPageContentSettings::text('nav_home') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('tenant.membership') }}">{{ PublicPageContentSettings::text('nav_apply') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('tenant.application.status') }}">{{ PublicPageContentSettings::text('nav_check_status') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('filament.member.auth.login') }}">{{ PublicPageContentSettings::text('nav_member_login') }}</a>
                    </li>
                    @if ($termsDownloadUrl)
                        <li>
                            <a href="{{ $termsDownloadUrl }}" @if (str_starts_with($termsDownloadUrl, 'http://') || str_starts_with($termsDownloadUrl, 'https://')) target="_blank" rel="noopener noreferrer"
                            @endif>
                                {{ PublicPageContentSettings::text('footer_terms') }}
                            </a>
                        </li>
                    @endif
                </ul>
            </div>

            <div class="tenant-public-footer__column">
                <h2 class="tenant-public-footer__heading">{{ PublicPageContentSettings::text('footer_contact_heading') }}</h2>
                <ul class="tenant-public-footer__links">
                    @if ($contactEmail)
                        <li>
                            <a href="mailto:{{ $contactEmail }}" dir="ltr">{{ $contactEmail }}</a>
                        </li>
                    @endif
                    @if ($contactPhone)
                        <li>
                            <a href="tel:{{ preg_replace('/\s+/', '', $contactPhone) }}" dir="ltr">{{ $contactPhone }}</a>
                        </li>
                    @endif
                    @if (!$contactEmail && !$contactPhone)
                        <li class="tenant-public-footer__muted">{{ PublicPageContentSettings::text('footer_contact_empty') }}
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="tenant-public-footer__bar">
            <p>{{ PublicPageContentSettings::text('footer_copyright', ['year' => date('Y'), 'fund' => $fundName]) }}</p>
        </div>
    </div>
</footer>
