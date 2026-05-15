@if (!empty($applicationDocUrl))
    <a href="{{ $applicationDocUrl }}" target="_blank" rel="noopener noreferrer" @class([
        'enrollment-download-form-btn',
        $class ?? null,
    ])>
        <svg class="enrollment-download-form-btn__icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"
            aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 16v-8m0 8l-3-3m3 3l3-3M4 16.5A2.5 2.5 0 006.5 19h11a2.5 2.5 0 002.5-2.5" />
        </svg>
        {{ __('Download Membership Application Form (PDF)') }}
    </a>
@endif