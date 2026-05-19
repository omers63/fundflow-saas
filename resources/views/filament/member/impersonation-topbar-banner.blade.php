@php
    $isImpersonating = session()->has('impersonator_user_id');
    $name = auth('tenant')->user()?->name;
@endphp

@if ($isImpersonating)
    <div class="ff-impersonation-banner" role="status" aria-live="polite">
        <span class="ff-impersonation-banner__dot" aria-hidden="true"></span>
        <span class="ff-impersonation-banner__text">
            {{ __('Impersonating: :name', ['name' => $name ?: __('Member')]) }}
        </span>
        <form method="post" action="{{ route('tenant.member.impersonation.stop') }}" class="ff-impersonation-banner__form">
            @csrf
            <button type="submit" class="ff-impersonation-banner__return">
                {{ __('Return to parent portal') }}
            </button>
        </form>
    </div>
@endif