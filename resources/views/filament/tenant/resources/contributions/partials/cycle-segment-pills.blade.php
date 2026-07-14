@php
use App\Filament\Tenant\Resources\Contributions\ContributionResource;

$activeSegment = ContributionResource::resolveCycleSegment();
[$month, $year] = ContributionResource::resolveListCycle();
$pending = ContributionResource::pendingCountForPeriod($month, $year);
$collected = ContributionResource::collectedContributionCount();
$arrears = ContributionResource::contributionArrearsPeriodCount(
    ContributionResource::memberFilterFromRequest(),
);
@endphp

<div class="mb-4 space-y-2">
    <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
        @foreach ([
                'collect' => __('To collect'),
                'collected' => __('Collected'),
                'arrears' => __('Arrears'),
            ] as $segment => $label)
                        <a href="{{ ContributionResource::listCycleSegmentUrl($segment) }}" @class([
                    'ff-tenant-tab-pills__item no-underline',
                    'ff-tenant-tab-pills__item--active' => $activeSegment === $segment,
                    'ff-tenant-tab-pills__item--warning' => $activeSegment !== $segment && $segment === 'collect' && $pending > 0,
                    'ff-tenant-tab-pills__item--danger' => $activeSegment !== $segment && $segment === 'arrears' && $arrears > 0,
                ])>
                            <x-ff-tab-pill-label :label="$label" :key="$segment" />
                            @if ($segment === 'collect' && $pending > 0)
                                <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $activeSegment === $segment,
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $activeSegment !== $segment,
                    ])>{{ $pending }}</span>
                            @elseif ($segment === 'collected' && $collected > 0)
                                <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $activeSegment === $segment,
                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' => $activeSegment !== $segment,
                    ])>{{ $collected }}</span>
                            @elseif ($segment === 'arrears' && $arrears > 0)
                                <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $activeSegment === $segment,
                        'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' => $activeSegment !== $segment,
                    ])>{{ $arrears }}</span>
                            @endif
                        </a>
        @endforeach
    </div>
</div>
