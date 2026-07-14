@php
use App\Filament\Tenant\Resources\Contributions\ContributionResource;

$activeView = ContributionResource::resolveLedgerView();
$arrearsCount = ContributionResource::contributionArrearsPeriodCount(
    ContributionResource::memberFilterFromRequest(),
);
@endphp

<div class="mb-4 space-y-2">
    <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
        <a href="{{ ContributionResource::listLedgerViewUrl() }}" @class([
    'ff-tenant-tab-pills__item no-underline',
    'ff-tenant-tab-pills__item--active' => $activeView === null,
])>
            <x-ff-tab-pill-label :label="__('All')" key="all" />
            </a>
            <a href="{{ ContributionResource::listLedgerViewUrl('arrears') }}" @class([
                'ff-tenant-tab-pills__item no-underline',
                'ff-tenant-tab-pills__item--active' => $activeView === 'arrears',
                'ff-tenant-tab-pills__item--danger' => $activeView !== 'arrears' && $arrearsCount > 0,
            ])>
            <x-ff-tab-pill-label :label="__('Arrears')" key="arrears" />
            @if ($arrearsCount > 0)
                <span @class([
        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
        'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $activeView === 'arrears',
        'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $activeView !== 'arrears',
    ])>{{ $arrearsCount }}</span>
            @endif
        </a>
    </div>
</div>
