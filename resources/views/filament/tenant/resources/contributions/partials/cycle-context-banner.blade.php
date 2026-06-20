@php
    use App\Filament\Tenant\Resources\Contributions\ContributionResource;
    use App\Services\ContributionCycleService;

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();
    $openLabel = $cycles->periodLabel($month, $year);
    $activeTab = ContributionResource::resolveListTab();
@endphp

<div
    class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-semibold text-gray-900 dark:text-white">{{ __('Open collection cycle') }}</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Active period: :period', ['period' => $openLabel]) }}
            </p>
        </div>
        <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
            @foreach ([
                    'collect' => __('To collect'),
                    'collected' => __('Collected'),
                    'contributions' => __('History'),
                    'arrears' => __('Arrears'),
                ] as $tab => $label)
                <a href="{{ ContributionResource::listTabUrl($tab) }}" @class([
                    'ff-tenant-tab-pills__item no-underline',
                    'ff-tenant-tab-pills__item--active' => $activeTab === $tab,
                ])>
                        {{ $label }}
                    </a>
            @endforeach
        </div>
    </div>
</div>
