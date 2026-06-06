@php
    use App\Filament\Member\Pages\BusinessDayTestingPage;
    use App\Filament\Tenant\Pages\Settings;

    $panelId = filament()->getCurrentPanel()?->getId();
    $settingsUrl = $panelId === 'member'
        ? BusinessDayTestingPage::getUrl()
        : Settings::getUrl();
    $settingsHint = $panelId === 'member'
        ? __('Change on Business calendar (testing).')
        : __('Update under Settings → General.');
@endphp

<div class="ff-status-footer-banner ff-status-footer-banner--business-day" role="status" aria-live="polite">
    <span class="ff-status-footer-banner__dot" aria-hidden="true"></span>
    <p class="ff-status-footer-banner__text">
        {{ __('Business day override active: app date is :business_day (calendar :calendar_day).', [
    'business_day' => \App\Support\BusinessDay::now()->toFormattedDateString(),
    'calendar_day' => \App\Support\BusinessDay::calendarToday()->toFormattedDateString(),
]) }}
        <a href="{{ $settingsUrl }}" class="ff-status-footer-banner__link">{{ $settingsHint }}</a>
    </p>
</div>