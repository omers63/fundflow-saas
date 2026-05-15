<div class="mx-auto max-w-4xl px-4 sm:px-6">
    @if ($enrollmentClosed)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
            <h1 class="mb-3 text-2xl font-bold text-gray-900">{{ __('Membership enrollment closed') }}</h1>
            <p class="mb-6 text-gray-600">
                {{ __('This fund is not accepting new members at the moment. Please check back later or contact the fund administrators.') }}
            </p>
            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <a href="{{ route('tenant.application.status') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white hover:bg-emerald-700">
                    {{ __('Check Application Status') }}
                </a>
                <a href="{{ route('tenant.home') }}" class="font-medium text-emerald-600 hover:text-emerald-700">
                    {{ __('Return to home') }}
                </a>
            </div>
        </div>
    @elseif ($submitted)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-10 text-center">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100">
                <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <h1 class="mb-2 text-2xl font-semibold text-gray-900">{{ __('Application submitted') }}</h1>
            <p class="mb-6 text-gray-600">
                {{ __('Thank you. Fund administrators will review your application and contact you by email.') }}
            </p>
            <div class="flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('tenant.application.status') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white hover:bg-emerald-700">
                    {{ __('Check Application Status') }}
                </a>
                <a href="{{ route('tenant.home') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-white px-6 py-3 font-semibold text-emerald-800 hover:bg-emerald-50">
                    {{ __('Return to home') }}
                </a>
            </div>
        </div>
    @else
        <header class="mb-8 text-center">
            <h1 class="mb-3 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                {{ __('Apply for membership') }}
            </h1>
            <p class="mx-auto max-w-2xl text-lg text-gray-600">
                {{ __('Complete the form below to join :fund.', ['fund' => $fundName]) }}
            </p>
            <div class="mt-4 flex flex-col items-center justify-center gap-3 sm:flex-row sm:flex-wrap">
                @include('livewire.tenant.partials.enrollment.download-membership-application-form')
                @include('livewire.tenant.partials.enrollment.download-terms-and-conditions', [
                    'termsDownloadUrl' => $termsDownloadUrl ?? null,
                ])
            </div>
            @if (!$noLimit && $remainingSlots !== null)
                <p class="mt-3 text-sm font-medium text-emerald-700">
                    {{ __(':count enrollment slot(s) remaining', ['count' => $remainingSlots]) }}
                </p>
            @endif
        </header>

        <x-membership-enrollment-stepper :steps="$steps" :current-step="$stepperCurrentStep" />

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
            @php $stepKind = $this->stepKindAt($step); @endphp

            @if ($stepKind === 'personal')
                @include('livewire.tenant.partials.enrollment.step-personal')
            @elseif ($stepKind === 'identity')
                @include('livewire.tenant.partials.enrollment.step-identity')
            @elseif ($stepKind === 'employment')
                @include('livewire.tenant.partials.enrollment.step-work')
            @elseif ($stepKind === 'document')
                @include('livewire.tenant.partials.enrollment.step-document')
            @elseif ($stepKind === 'payment')
                @include('livewire.tenant.partials.enrollment.step-fees')
            @endif

            <div class="mt-8 flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:justify-between">
                @if ($step > 1)
                    <button type="button" wire:click="previousStep"
                        class="inline-flex items-center justify-center rounded-xl border border-gray-200 px-6 py-3 font-semibold text-gray-700 hover:bg-gray-50">
                        &lt; {{ __('Previous') }}
                    </button>
                @else
                    <div></div>
                @endif

                @if ($step < $lastStep)
                    <button type="button" wire:click="nextStep"
                        class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-8 py-3 font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="nextStep">{{ __('Next') }} &gt;</span>
                        <span wire:loading wire:target="nextStep">{{ __('Saving…') }}</span>
                    </button>
                @else
                    <button type="button" wire:click="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-8 py-3 font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700"
                        wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submit">{{ __('Submit application') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Submitting…') }}</span>
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>