<div class="fi-sc-section-description-ctn space-y-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
    <p>
        {{ __('Please upload a readable scan or photo of your') }} <strong>{{ __('signed and dated') }}</strong>
        {{ __('membership application form.') }}
        {{ __('The signature and date should match the applicant named in this record and should appear where the form asks for them, so we can confirm your application against the information you have provided.') }}
    </p>
    @if (filled($downloadUrl ?? null))
        <p>
            <a href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer"
                class="fi-link fi-size-md inline-flex items-center gap-1.5 font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                {{ __('Download blank form template (PDF)') }}
            </a>
            <span class="text-gray-500 dark:text-gray-500"> —
                {{ __('print or fill it digitally, then sign and date it before you upload.') }}</span>
        </p>
    @endif
</div>