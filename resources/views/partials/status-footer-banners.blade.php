@php
    $showBusinessDay = \App\Support\BusinessDay::isOverridden();
    $showImpersonation = session()->has('impersonator_user_id');
@endphp

@if ($showBusinessDay || $showImpersonation)
    <div @class([
        'ff-status-footer-banners',
        'ff-status-footer-banners--double' => $showBusinessDay && $showImpersonation,
    ])>
        @if ($showBusinessDay)
            @include('partials.business-day-footer-banner')
        @endif

        @if ($showImpersonation)
            @include('partials.impersonation-footer-banner')
        @endif
    </div>
@endif