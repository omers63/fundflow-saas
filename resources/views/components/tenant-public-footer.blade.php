@php
    use App\Support\PublicPageSettings;

    $fundName = PublicPageSettings::fundName(tenant('name'));
    $termsDownloadUrl = PublicPageSettings::termsAndConditionsDownloadUrl();
    $contactEmail = PublicPageSettings::contactEmail();
    $contactPhone = PublicPageSettings::contactPhone();
@endphp

<footer class="tenant-public-footer mt-auto bg-gray-900 py-12 text-gray-400 sm:py-16" aria-label="{{ __('Site footer') }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-10 grid gap-10 md:grid-cols-4">
            <div class="md:col-span-2">
                <div class="mb-4 flex items-center gap-3">
                    <x-fund-logo variant="panel" height="3.5rem" class="tenant-public-footer__logo" />
                    <span class="text-xl font-bold text-white">{{ $fundName }}</span>
                </div>
                <p class="max-w-xs text-sm leading-relaxed text-gray-400">
                    {{ __('A transparent and trusted family fund management platform — built on mutual support and zero-interest principles.') }}
                </p>
            </div>

            <div>
                <h2 class="mb-4 text-sm font-semibold text-white">{{ __('Quick links') }}</h2>
                <ul class="space-y-2 text-sm">
                    <li>
                        <a href="{{ route('tenant.membership') }}"
                            class="transition-colors hover:text-sky-400">{{ __('Apply for membership') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('tenant.application.status') }}"
                            class="transition-colors hover:text-sky-400">{{ __('Check application status') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('filament.member.auth.login') }}"
                            class="transition-colors hover:text-sky-400">{{ __('Member login') }}</a>
                    </li>
                    @if ($termsDownloadUrl)
                        <li>
                            <a href="{{ $termsDownloadUrl }}"
                                @if (str_starts_with($termsDownloadUrl, 'http://') || str_starts_with($termsDownloadUrl, 'https://')) target="_blank" rel="noopener noreferrer" @endif
                                class="transition-colors hover:text-sky-400">{{ __('Terms & conditions (PDF)') }}</a>
                        </li>
                    @endif
                </ul>
            </div>

            <div>
                <h2 class="mb-4 text-sm font-semibold text-white">{{ __('Contact') }}</h2>
                <ul class="space-y-2 text-sm text-gray-400">
                    @if ($contactEmail)
                        <li>
                            <a href="mailto:{{ $contactEmail }}" dir="ltr"
                                class="transition-colors hover:text-sky-400">{{ $contactEmail }}</a>
                        </li>
                    @endif
                    @if ($contactPhone)
                        <li>
                            <a href="tel:{{ preg_replace('/\s+/', '', $contactPhone) }}" dir="ltr"
                                class="transition-colors hover:text-sky-400">{{ $contactPhone }}</a>
                        </li>
                    @endif
                    @if (! $contactEmail && ! $contactPhone)
                        <li class="text-gray-500">{{ __('Contact details are configured in fund settings.') }}</li>
                    @endif
                </ul>
            </div>
        </div>

        <div
            class="flex flex-col items-center justify-between gap-4 border-t border-gray-800 pt-8 text-sm text-gray-500 md:flex-row">
            <p>{{ __(':year © :fund. All rights reserved.', ['year' => date('Y'), 'fund' => $fundName]) }}</p>
            <p>{{ __('Managed in :currency (Saudi Riyal)', ['currency' => __('SAR')]) }}</p>
        </div>
    </div>
</footer>
