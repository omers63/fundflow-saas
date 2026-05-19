@php
    use App\Support\PublicPageSettings;

    $fundName = PublicPageSettings::fundName(tenant('name'));
    $termsDownloadUrl = PublicPageSettings::termsAndConditionsDownloadUrl();
    $contactEmail = PublicPageSettings::contactEmail();
    $contactPhone = PublicPageSettings::contactPhone();
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
                    {{ __('A transparent family fund platform — mutual support and zero-interest principles.') }}
                </p>
            </div>

            <div class="tenant-public-footer__column">
                <h2 class="tenant-public-footer__heading">{{ __('Quick links') }}</h2>
                <ul class="tenant-public-footer__links">
                    <li>
                        <a href="{{ route('tenant.home') }}">{{ __('Home') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('tenant.membership') }}">{{ __('Apply for membership') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('tenant.application.status') }}">{{ __('Check application status') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('filament.member.auth.login') }}">{{ __('Member login') }}</a>
                    </li>
                    @if ($termsDownloadUrl)
                        <li>
                            <a href="{{ $termsDownloadUrl }}" @if (str_starts_with($termsDownloadUrl, 'http://') || str_starts_with($termsDownloadUrl, 'https://')) target="_blank" rel="noopener noreferrer"
                            @endif>
                                {{ __('Terms & conditions (PDF)') }}
                            </a>
                        </li>
                    @endif
                </ul>
            </div>

            <div class="tenant-public-footer__column">
                <h2 class="tenant-public-footer__heading">{{ __('Contact') }}</h2>
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
                        <li class="tenant-public-footer__muted">{{ __('Contact details are configured in fund settings.') }}
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="tenant-public-footer__bar">
            <p>{{ __(':year © :fund. All rights reserved.', ['year' => date('Y'), 'fund' => $fundName]) }}</p>
            <p>{{ __('Managed in :currency (Saudi Riyal)', ['currency' => __('SAR')]) }}</p>
        </div>
    </div>
</footer>