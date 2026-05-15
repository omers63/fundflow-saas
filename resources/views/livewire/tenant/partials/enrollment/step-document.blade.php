<h2 class="mb-6 text-xl font-bold text-gray-900">
    {{ __('Step :number: :title', ['number' => $step, 'title' => __('Application document')]) }}
</h2>

@if (!empty($applicationDocUrl))
    <div class="mb-6">
        @include('livewire.tenant.partials.enrollment.download-membership-application-form')
    </div>
@endif

@if (! empty($termsDownloadUrl))
    <div class="mb-6">
        @include('livewire.tenant.partials.enrollment.download-terms-and-conditions', [
            'termsDownloadUrl' => $termsDownloadUrl,
        ])
    </div>
@endif

<div class="space-y-6">
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-5">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <p class="text-sm font-semibold text-gray-700">{{ __('Application form upload') }}</p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ __('Upload a signed copy of the membership application form. Accepted formats: PDF, JPG, PNG (max 5MB). This step is optional but recommended.') }}
                </p>
            </div>
        </div>
    </div>

    <div>
        <label
            class="mb-3 block text-sm font-medium text-gray-700">{{ __('Signed application form (optional)') }}</label>
        <label
            class="flex h-40 w-full cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-gray-300 transition-all hover:border-emerald-400 hover:bg-emerald-50/50">
            <input wire:model="application_form" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png">
            @if ($application_form)
                <div class="text-center">
                    <svg class="mx-auto mb-2 h-10 w-10 text-emerald-500" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm font-semibold text-emerald-600">{{ __('File selected') }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $application_form->getClientOriginalName() }}</p>
                </div>
            @else
                <div class="text-center">
                    <svg class="mx-auto mb-2 h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <p class="text-sm font-semibold text-gray-600">{{ __('Click to upload') }}</p>
                    <p class="mt-1 text-xs text-gray-400">{{ __('PDF, JPG, PNG up to 5MB') }}</p>
                </div>
            @endif
        </label>
        @error('application_form') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div wire:loading wire:target="application_form" class="flex items-center gap-2 text-sm text-emerald-600">
        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        {{ __('Uploading file…') }}
    </div>

    <div>
        <label for="message" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Additional message (optional)') }}
        </label>
        <textarea wire:model="message" id="message" rows="3"
            class="w-full resize-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
            placeholder="{{ __('Any notes for the fund administrators…') }}"></textarea>
        @error('message') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>
</div>