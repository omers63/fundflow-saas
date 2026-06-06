@php
    $name = auth('tenant')->user()?->name;
@endphp

<div class="ff-status-footer-banner ff-status-footer-banner--impersonation" role="status" aria-live="polite">
    <span class="ff-status-footer-banner__dot" aria-hidden="true"></span>
    <span class="ff-status-footer-banner__text">
        {{ __('Impersonating: :name', ['name' => $name ?: __('Member')]) }}
    </span>
    <form method="post" action="{{ route('tenant.member.impersonation.stop') }}" class="ff-status-footer-banner__form">
        @csrf
        <button type="submit" class="ff-status-footer-banner__action">
            {{ __('Return to parent portal') }}
        </button>
    </form>
</div>