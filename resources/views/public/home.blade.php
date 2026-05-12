<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $settings['public_primary_color'] ?? '#F53003' }}">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/icons/icon-192.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/icon-192.svg">

        <title>{{ $settings['app_name'] ?? config('app.name', 'FundFlow') }}</title>

        @fonts

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            @include('partials.welcome-inline-tailwind')
        @endif
    </head>
    <body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
        <header class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6">
            <nav class="flex flex-wrap items-center justify-end gap-2 sm:gap-4">
                <span class="me-auto text-[13px] font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ $settings['app_name'] ?? 'FundFlow' }}</span>
                <a
                    href="{{ route('locale.switch', 'en') }}"
                    class="inline-block px-4 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal"
                >EN</a>
                <a
                    href="{{ route('locale.switch', 'ar') }}"
                    class="inline-block px-4 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal"
                >AR</a>
                <a
                    href="{{ url('/admin/login') }}"
                    class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal"
                >{{ __('Operator / Admin') }}</a>
                <a
                    href="{{ url('/member/login') }}"
                    class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal"
                >{{ __('Family member') }}</a>
                @if (Route::has('login'))
                    @auth
                        <a
                            href="{{ url('/dashboard') }}"
                            class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal"
                        >
                            {{ __('Dashboard') }}
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal"
                        >
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal"
                            >{{ __('Register') }}</a>
                        @endif
                    @endauth
                @endif
            </nav>
        </header>
        <div class="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow starting:opacity-0">
            <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row">
                <div class="text-[13px] leading-[20px] flex-1 p-6 pb-6 lg:p-20 lg:pb-10 bg-white dark:bg-[#161615] dark:text-[#EDEDEC] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-[#706f6c] dark:text-[#A1A09A]">{{ __('Family Fund Management Platform') }}</p>
                    <h1 class="mb-1 font-medium">{{ $settings['public_hero_title'] ?? __("Let's get started") }}</h1>
                    <p class="mb-2 text-[#706f6c] dark:text-[#A1A09A]">{{ $settings['public_hero_subtitle'] ?? __('Manage family funds, sponsorships, enrollment workflows, and bilingual operations in one secure workspace.') }}</p>
                    <p class="mb-4 text-[#706f6c] dark:text-[#A1A09A]">{{ __('With so many options available to you, we suggest you start with the following:') }}</p>
                    <ul class="flex flex-col mb-4 lg:mb-6">
                        <li class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:top-1/2 before:bottom-0 before:left-[0.4rem] before:absolute">
                            <span class="relative py-1 bg-white dark:bg-[#161615]">
                                <span class="flex items-center justify-center rounded-full bg-[#FDFDFC] dark:bg-[#161615] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)] w-3.5 h-3.5 border dark:border-[#3E3E3A] border-[#e3e3e0]">
                                    <span class="rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A] w-1.5 h-1.5"></span>
                                </span>
                            </span>
                            <span>
                                {{ __('Read the') }}
                                <a href="https://laravel.com/docs" target="_blank" rel="noopener noreferrer" class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1">
                                    <span>{{ __('Documentation') }}</span>
                                    <svg
                                        width="10"
                                        height="11"
                                        viewBox="0 0 10 11"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="w-2.5 h-2.5"
                                    >
                                        <path
                                            d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001"
                                            stroke="currentColor"
                                            stroke-linecap="square"
                                        />
                                    </svg>
                                </a>
                            </span>
                        </li>
                        <li class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:bottom-1/2 before:top-0 before:left-[0.4rem] before:absolute">
                            <span class="relative py-1 bg-white dark:bg-[#161615]">
                                <span class="flex items-center justify-center rounded-full bg-[#FDFDFC] dark:bg-[#161615] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)] w-3.5 h-3.5 border dark:border-[#3E3E3A] border-[#e3e3e0]">
                                    <span class="rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A] w-1.5 h-1.5"></span>
                                </span>
                            </span>
                            <span>
                                {{ __('Watch video tutorials at') }}
                                <a href="https://laracasts.com" target="_blank" rel="noopener noreferrer" class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1">
                                    <span>Laracasts</span>
                                    <svg
                                        width="10"
                                        height="11"
                                        viewBox="0 0 10 11"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="w-2.5 h-2.5"
                                    >
                                        <path
                                            d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001"
                                            stroke="currentColor"
                                            stroke-linecap="square"
                                        />
                                    </svg>
                                </a>
                            </span>
                        </li>
                    </ul>

                    <p class="mb-2 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Advanced Features for Family Funds') }}</p>
                    <ul class="mb-4 flex flex-col gap-2 text-[#706f6c] dark:text-[#A1A09A]">
                        <li>— {{ __('Multi-tenant family workspaces with isolated data boundaries') }}</li>
                        <li>— {{ __('Parent-dependent sponsorship modeling and linked member records') }}</li>
                        <li>— {{ __('Enrollment pipeline with statuses, reviewer actions, and timeline control') }}</li>
                        <li>— {{ __('Subscription-ready architecture with usage tracking hooks') }}</li>
                        <li>— {{ __('Role-based access via Admin and Member portals with distinct UX themes') }}</li>
                    </ul>

                    <p class="mb-2 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Operational Excellence') }}</p>
                    <ul class="mb-4 flex flex-col gap-2 text-[#706f6c] dark:text-[#A1A09A]">
                        <li>— {{ __('Centralized system settings for branding, content, and maintenance mode') }}</li>
                        <li>— {{ __('Database notifications for actionable alerts across workflows') }}</li>
                        <li>— {{ __('Comment-enabled communication layer for admins and members') }}</li>
                        <li>— {{ __('Mobile installable PWA experience with offline fallback support') }}</li>
                        <li>— {{ __('Bilingual English/Arabic public and panel-ready interface') }}</li>
                    </ul>

                    <p class="mb-2 font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('How it works') }}</p>
                    <ul class="mb-6 flex flex-col gap-3 text-[#706f6c] dark:text-[#A1A09A]">
                        <li><span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Step 1') }}.</span> {{ __('Operators onboard families as secure tenants with dedicated domains.') }}</li>
                        <li><span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Step 2') }}.</span> {{ __('Families enroll members, define sponsorship links, and manage approvals.') }}</li>
                        <li><span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ __('Step 3') }}.</span> {{ __('Teams collaborate with comments, notifications, and full audit visibility.') }}</li>
                    </ul>

                    <ul class="flex flex-wrap gap-3 text-sm leading-normal">
                        <li>
                            <a href="https://cloud.laravel.com" target="_blank" rel="noopener noreferrer" class="inline-block dark:bg-[#eeeeec] dark:border-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white dark:hover:border-white hover:bg-black hover:border-black px-5 py-1.5 bg-[#1b1b18] rounded-sm border border-black text-white text-sm leading-normal">
                                {{ __('Deploy now') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ url('/admin/login') }}" class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                                {{ __('Operator / Admin login') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ url('/member/login') }}" class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                                {{ __('Family member login') }}
                            </a>
                        </li>
                    </ul>

                    <p class="mt-6 lg:mt-10 text-[#706f6c] dark:text-[#A1A09A]">
                        Laravel v{{ app()->version() }}
                        <a href="https://github.com/laravel/framework/blob/13.x/CHANGELOG.md" target="_blank" rel="noopener noreferrer" class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1">
                            <span>{{ __('View changelog') }}</span>
                            <svg
                                width="10"
                                height="11"
                                viewBox="0 0 10 11"
                                fill="none"
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-2.5 h-2.5"
                            >
                                <path
                                    d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001"
                                    stroke="currentColor"
                                    stroke-linecap="square"
                                />
                            </svg>
                        </a>
                    </p>
                </div>
                @include('partials.welcome-hero-panel')
            </main>
        </div>

        <div class="h-14.5 hidden lg:block"></div>
    </body>
</html>
