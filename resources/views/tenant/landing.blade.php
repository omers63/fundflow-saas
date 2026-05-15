@php
    $fundName = \App\Support\PublicPageSettings::fundName(tenant('name'));
@endphp

<x-layouts.tenant-public :title="$fundName">
    {{-- Hero Section --}}
    <section class="landing-hero-section">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="landing-hero-card max-w-4xl mx-auto">
                <div class="landing-hero-card__inner">
                    <div class="landing-hero-badge">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                        {{ __('Trusted family fund management') }}
                    </div>

                    <h1 class="landing-hero-title">
                        {{ __('Building wealth') }}
                        <span class="landing-hero-title-accent">{{ __('together') }}</span>
                        {{ __('as a family') }}
                    </h1>

                    <p class="landing-hero-lead">
                        {{ __('Join our family fund where every member contributes monthly, builds savings, and has access to interest-free loans. Transparent, accountable, and designed for your financial growth.') }}
                    </p>

                    <div class="flex flex-col items-center justify-center gap-4">
                        <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <a href="{{ route('tenant.membership') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-8 py-3.5 font-semibold text-white shadow-lg shadow-emerald-600/25 transition-all hover:bg-emerald-700 hover:shadow-emerald-600/40 sm:w-auto">
                                {{ __('Apply for membership') }}
                                <svg class="ml-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                </svg>
                            </a>
                            <a href="{{ route('filament.member.auth.login') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-emerald-200/80 bg-white/80 px-8 py-3.5 font-semibold text-emerald-900 transition-all hover:border-emerald-300 hover:bg-white sm:w-auto">
                                {{ __('Member login') }}
                            </a>
                        </div>
                        @if (\App\Support\PublicPageSettings::hasTermsAndConditionsDownload())
                            <div class="flex justify-center">
                                @include('livewire.tenant.partials.enrollment.download-terms-and-conditions')
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Stats --}}
            <div class="mt-20 grid grid-cols-2 lg:grid-cols-4 gap-6 max-w-4xl mx-auto">
                <div class="text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="text-3xl font-bold text-emerald-600">
                        {{ \App\Models\Tenant\Member::active()->count() ?: '50+' }}
                    </div>
                    <div class="text-sm text-gray-500 mt-1">Active Members</div>
                </div>
                <div class="text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="text-3xl font-bold text-emerald-600">12+</div>
                    <div class="text-sm text-gray-500 mt-1">Months Track Record</div>
                </div>
                <div class="text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="text-3xl font-bold text-emerald-600">100%</div>
                    <div class="text-sm text-gray-500 mt-1">Transparent</div>
                </div>
                <div class="text-center p-6 bg-white rounded-2xl shadow-sm border border-gray-100">
                    <div class="text-3xl font-bold text-emerald-600">Low</div>
                    <div class="text-sm text-gray-500 mt-1">Interest Rates</div>
                </div>
            </div>
        </div>
    </section>

    {{-- Features Section --}}
    <section id="features" class="py-20 sm:py-28 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4">Everything You Need to Grow</h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto">Our fund provides a complete financial ecosystem for
                    family members to save, grow, and access funds when needed.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                {{-- Membership Management --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Membership Management') }}</h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ __('Easy application process, online form submission, and admin approval workflow with instant notifications.') }}
                    </p>
                </div>

                {{-- Monthly Contributions --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Monthly Contributions</h3>
                    <p class="text-gray-600 leading-relaxed">Flexible contribution amounts tailored to each member.
                        Build your savings consistently with automated tracking and receipts.</p>
                </div>

                {{-- Feature 2 --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Affordable Loans</h3>
                    <p class="text-gray-600 leading-relaxed">Access loans at favorable rates after one year of
                        membership. Funded from the collective fund with fair, transparent terms.</p>
                </div>

                {{-- Feature 3 --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Family Structure</h3>
                    <p class="text-gray-600 leading-relaxed">Support parent-dependent relationships where parents can
                        manage contributions for their dependents with flexible allocation.</p>
                </div>

                {{-- Monthly Statements --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Monthly Statements') }}</h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ __('Download detailed PDF statements showing contributions, loan repayments, and account balance.') }}
                    </p>
                </div>

                {{-- Smart Notifications --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Smart Notifications') }}</h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ __('Receive real-time email, SMS, and WhatsApp alerts for approvals, loans, and payment reminders.') }}
                    </p>
                </div>

                {{-- Admin dashboard & transparent accounting --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">
                        {{ __('Admin dashboard & transparent accounting') }}</h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ __('Comprehensive oversight with statistics, delinquency handling, and bulk management tools. Every transaction is recorded and traceable with real-time balances and full history.') }}
                    </p>
                </div>

                {{-- Dual Account System --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Dual Account System</h3>
                    <p class="text-gray-600 leading-relaxed">Each member maintains a Cash account for incoming funds and
                        a Fund account for long-term savings. Clear separation, easy tracking.</p>
                </div>

                {{-- Feature 6 --}}
                <div
                    class="group p-8 rounded-2xl bg-gray-50 hover:bg-emerald-50 border border-gray-100 hover:border-emerald-200 transition-all duration-300">
                    <div
                        class="w-12 h-12 bg-emerald-100 group-hover:bg-emerald-200 rounded-xl flex items-center justify-center mb-5 transition-colors">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Secure & Private</h3>
                    <p class="text-gray-600 leading-relaxed">Your fund operates in its own isolated environment. Data is
                        private to your family with enterprise-grade security.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- How It Works --}}
    <section id="how-it-works" class="py-20 sm:py-28 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4">How It Works</h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto">Getting started is simple. Join, contribute, and
                    benefit from the collective fund.</p>
            </div>

            <div class="grid md:grid-cols-4 gap-8 max-w-5xl mx-auto">
                <div class="text-center">
                    <div
                        class="w-14 h-14 bg-emerald-600 text-white rounded-2xl flex items-center justify-center text-xl font-bold mx-auto mb-4">
                        1</div>
                    <h3 class="font-semibold text-gray-900 mb-2">Apply</h3>
                    <p class="text-sm text-gray-600">
                        {{ __('Start your membership application on our dedicated enrollment page.') }}
                    </p>
                </div>
                <div class="text-center">
                    <div
                        class="w-14 h-14 bg-emerald-600 text-white rounded-2xl flex items-center justify-center text-xl font-bold mx-auto mb-4">
                        2</div>
                    <h3 class="font-semibold text-gray-900 mb-2">Get Approved</h3>
                    <p class="text-sm text-gray-600">Fund administrators review your application and activate your
                        membership.</p>
                </div>
                <div class="text-center">
                    <div
                        class="w-14 h-14 bg-emerald-600 text-white rounded-2xl flex items-center justify-center text-xl font-bold mx-auto mb-4">
                        3</div>
                    <h3 class="font-semibold text-gray-900 mb-2">Contribute</h3>
                    <p class="text-sm text-gray-600">Make monthly contributions that are tracked in your personal fund
                        account.</p>
                </div>
                <div class="text-center">
                    <div
                        class="w-14 h-14 bg-emerald-600 text-white rounded-2xl flex items-center justify-center text-xl font-bold mx-auto mb-4">
                        4</div>
                    <h3 class="font-semibold text-gray-900 mb-2">Access Loans</h3>
                    <p class="text-sm text-gray-600">After 12 months, apply for affordable loans funded by the
                        collective.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Membership CTA --}}
    <section id="apply" class="landing-cta-section">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="landing-cta-card">
                <h2 class="landing-cta-card__title">{{ __('Ready to join your family fund?') }}</h2>
                <p class="landing-cta-card__text">
                    {{ __('Apply for membership today and start building a stronger financial future together.') }}
                </p>
                <div class="landing-cta-card__actions">
                    <a href="{{ route('tenant.membership') }}" class="landing-cta-card__btn-primary">
                        {{ __('Apply for membership') }}
                    </a>
                    <a href="{{ route('tenant.application.status') }}" class="landing-cta-card__btn-secondary">
                        {{ __('Check status') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

</x-layouts.tenant-public>