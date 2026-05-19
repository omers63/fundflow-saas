<div class="application-status-page">
    <div class="tenant-centered-page px-4 sm:px-6">
        <div class="tenant-centered-page__panel">
            <div class="application-status-page__inner w-full max-w-lg">
                <header class="mb-6 text-center sm:mb-8">
                    <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">{{ __('Check Application Status') }}</h1>
                    <p class="mt-2 text-gray-600">
                        {{ __('Enter your email and National ID to check the status of your membership application.') }}
                    </p>
                </header>

                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="enrollment-status-card-header">
            <div class="flex items-center gap-3 text-white">
                <svg class="h-6 w-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                <h2 class="text-lg font-bold">{{ __('Application lookup') }}</h2>
            </div>
        </div>

        <div class="p-5 sm:p-8">
            <form wire:submit="check" class="space-y-5">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">
                        {{ __('Email address') }} <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="email" type="email" id="email" placeholder="{{ __('you@example.com') }}"
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                    @error('email') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="national_id" class="mb-1.5 block text-sm font-medium text-gray-700">
                        {{ __('National ID') }} <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="national_id" type="text" id="national_id" placeholder="{{ __('1XXXXXXXXX') }}"
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                    @error('national_id') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="check"
                    class="enrollment-status-submit-btn w-full disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('Check status') }}
                </button>
            </form>

            @if ($searched)
                <div class="mt-8 border-t border-gray-100 pt-6">
                    @if ($result)
                        @if ($result['status'] === 'pending')
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                                <div class="mb-3 flex items-center gap-3">
                                    <svg class="h-6 w-6 shrink-0 text-amber-600" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <p class="font-bold text-amber-800">{{ __('Pending review') }}</p>
                                        <p class="text-xs text-amber-700">
                                            {{ __('Applicant:') }} {{ $result['name'] }}
                                        </p>
                                    </div>
                                </div>
                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-600">{{ __('Submitted') }}</dt>
                                        <dd class="font-medium text-gray-900">{{ $result['submitted_at'] }}</dd>
                                    </div>
                                    @if ($result['city'])
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-gray-600">{{ __('City') }}</dt>
                                            <dd class="font-medium text-gray-900">{{ $result['city'] }}</dd>
                                        </div>
                                    @endif
                                </dl>
                                <p class="mt-3 text-xs text-amber-800">
                                    {{ __('Your application is being reviewed. You will be notified when a decision is made.') }}
                                </p>
                            </div>
                        @elseif ($result['status'] === 'approved')
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                                <div class="mb-3 flex items-center gap-3">
                                    <svg class="h-6 w-6 shrink-0 text-emerald-600" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    <div>
                                        <p class="font-bold text-emerald-900">{{ __('Approved!') }}</p>
                                        <p class="text-xs text-emerald-800">
                                            {{ __('Applicant:') }} {{ $result['name'] }}
                                        </p>
                                    </div>
                                </div>
                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-600">{{ __('Submitted') }}</dt>
                                        <dd class="font-medium text-gray-900">{{ $result['submitted_at'] }}</dd>
                                    </div>
                                    @if ($result['reviewed_at'])
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-gray-600">{{ __('Approved') }}</dt>
                                            <dd class="font-medium text-gray-900">{{ $result['reviewed_at'] }}</dd>
                                        </div>
                                    @endif
                                    @if ($result['city'])
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-gray-600">{{ __('City') }}</dt>
                                            <dd class="font-medium text-gray-900">{{ $result['city'] }}</dd>
                                        </div>
                                    @endif
                                </dl>
                                <div class="mt-3 border-t border-emerald-200 pt-3">
                                    <a href="{{ route('filament.member.auth.login') }}"
                                        class="text-sm font-semibold text-emerald-800 hover:text-emerald-900">
                                        {{ __('Sign in to your account') }} →
                                    </a>
                                </div>
                            </div>
                        @else
                            <div class="rounded-2xl border border-red-200 bg-red-50 p-5">
                                <div class="mb-3 flex items-center gap-3">
                                    <svg class="h-6 w-6 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <p class="font-bold text-red-800">{{ __('Not approved') }}</p>
                                        <p class="text-xs text-red-700">
                                            {{ __('Applicant:') }} {{ $result['name'] }}
                                        </p>
                                    </div>
                                </div>
                                <dl class="space-y-1 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-600">{{ __('Submitted') }}</dt>
                                        <dd class="font-medium text-gray-900">{{ $result['submitted_at'] }}</dd>
                                    </div>
                                    @if ($result['reviewed_at'])
                                        <div class="flex justify-between gap-4">
                                            <dt class="text-gray-600">{{ __('Reviewed') }}</dt>
                                            <dd class="font-medium text-gray-900">{{ $result['reviewed_at'] }}</dd>
                                        </div>
                                    @endif
                                </dl>
                                @if ($result['rejection_reason'])
                                    <div class="mt-3 border-t border-red-200 pt-3">
                                        <p class="text-xs font-medium text-red-700">{{ __('Reason:') }}</p>
                                        <p class="mt-1 text-sm italic text-red-800">"{{ $result['rejection_reason'] }}"</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @else
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-5 text-center">
                            <svg class="mx-auto mb-2 h-10 w-10 text-gray-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="font-medium text-gray-700">{{ __('No application found') }}</p>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ __('No application matches the provided email and National ID.') }}
                            </p>
                            <a href="{{ route('tenant.apply') }}"
                                class="mt-3 inline-block text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                                {{ __('Apply for membership') }} →
                            </a>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
            </div>
        </div>
    </div>
</div>