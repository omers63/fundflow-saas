@php
$panelId = filament()->getCurrentPanel()?->getId();
$showBusinessDay = \App\Support\BusinessDaySettings::shouldShowFooterBanner($panelId);
@endphp

@if ($showBusinessDay)
    <div class="ff-status-footer-banners">
        @include('partials.business-day-footer-banner')
    </div>
@endif
