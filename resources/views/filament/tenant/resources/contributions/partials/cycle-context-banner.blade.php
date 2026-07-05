@php
    use App\Filament\Tenant\Resources\Contributions\ContributionResource;
    use App\Services\ContributionCycleService;

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = ContributionResource::resolveListCycle();
    $periodLabel = $cycles->periodLabel($month, $year);
    $activeTab = ContributionResource::resolveListTab();
    $cycleOptions = $cycles->contributionCycleSelectOptionsForBulk();
    $isOpenCycle = ContributionResource::isViewingOpenCycle();
@endphp
    
    <div
        class="mb-4 overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ __('Collection cycle') }}</p>
                    @unless ($isOpenCycle)
                        <span
                            class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 dark:bg-amber-500/15 dark:text-amber-200">
                            {{ __('Past cycle') }}
                        </span>
                    @endunless
                </div>
                <div class="flex max-w-md flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                    <label for="contribution-cycle-select" class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Period') }}
                    </label>
                    <select id="contribution-cycle-select" wire:model.live="selectedCycle"
                        class="w-full min-w-[12rem] rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:border-white/10 dark:bg-gray-900 dark:text-white">
                        @foreach ($cycleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Viewing: :period', ['period' => $periodLabel]) }}
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
