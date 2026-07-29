@php
    use App\Support\BusinessDay;
    use App\Support\PublicPageSettings;

    $panelId = filament()->getCurrentPanel()?->getId();
    $fundName = PublicPageSettings::fundName(tenant('name'));
    $portalLabel = $panelId === 'member'
        ? __('Member portal')
        : __('Admin portal');
    $todayLabel = BusinessDay::today()->toFormattedDateString();
    $isOverridden = BusinessDay::isOverridden();
@endphp

<footer class="ff-portal-bottom-bar" role="contentinfo" data-panel="{{ $panelId }}">
    <div class="ff-portal-bottom-bar__accent" aria-hidden="true"></div>
    <div class="ff-portal-bottom-bar__inner">
        <div class="ff-portal-bottom-bar__start">
            <span class="ff-portal-bottom-bar__fund" title="{{ $fundName }}">{{ $fundName }}</span>
            <span class="ff-portal-bottom-bar__divider" aria-hidden="true"></span>
            <span class="ff-portal-bottom-bar__portal">{{ $portalLabel }}</span>
        </div>
        <div class="ff-portal-bottom-bar__end">
            <span @class([
                'ff-portal-bottom-bar__date',
                'ff-portal-bottom-bar__date--override' => $isOverridden,
            ]) title="{{ $isOverridden ? __('Business day override active') : __('Today') }}">
                {{ $todayLabel }}
            </span>
        </div>
    </div>
</footer>