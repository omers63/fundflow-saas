@php
    use App\Filament\Tenant\Support\SmsClearingTabRegistry;

    $filters = SmsClearingTabRegistry::queueFilters();
    $unmatchedCount = $this->getUnmatchedQueueCount();
    $readyCount = $this->getReadyQueueCount();
@endphp

<div class="ff-sms-clearing-filter-chips flex flex-wrap items-center gap-2">
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Filter') }}</span>
    @foreach ($filters as $key => $label)
        @php
            $count = match ($key) {
                SmsClearingTabRegistry::FILTER_UNMATCHED => $unmatchedCount,
                SmsClearingTabRegistry::FILTER_READY => $readyCount,
                default => $unmatchedCount + $readyCount,
            };
        @endphp
        <button type="button" wire:click="setQueueFilter('{{ $key }}')" @class([
            'ff-sms-clearing-filter-chip inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition',
            'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-200' => ($queueFilter ?? SmsClearingTabRegistry::FILTER_ALL) === $key,
            'border-gray-200 bg-white text-gray-700 hover:border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-white/20' => ($queueFilter ?? SmsClearingTabRegistry::FILTER_ALL) !== $key,
        ])>
            {{ $label }}
            @if ($count > 0)
                <span
                    class="inline-flex min-w-[1.125rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $count }}</span>
            @endif
        </button>
    @endforeach
</div>