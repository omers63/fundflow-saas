@php
    use App\Filament\Tenant\Support\BankClearingTabRegistry;

    $filters = BankClearingTabRegistry::queueFilters();
    $bankFileCount = $this->getBankFileQueueCount();
    $operationsCount = $this->getOperationsQueueCount();
@endphp

<div class="ff-bank-clearing-filter-chips flex flex-wrap items-center gap-2">
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Filter') }}</span>
    @foreach ($filters as $key => $label)
        @php
            $count = match ($key) {
                BankClearingTabRegistry::FILTER_BANK_FILE => $bankFileCount,
                BankClearingTabRegistry::FILTER_OPERATIONS => $operationsCount,
                default => $bankFileCount + $operationsCount,
            };
        @endphp
        <button type="button" wire:click="setQueueFilter('{{ $key }}')" @class([
            'ff-bank-clearing-filter-chip inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition',
            'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-200' => ($queueFilter ?? BankClearingTabRegistry::FILTER_ALL) === $key,
            'border-gray-200 bg-white text-gray-700 hover:border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-white/20' => ($queueFilter ?? BankClearingTabRegistry::FILTER_ALL) !== $key,
        ])>
            {{ $label }}
            @if ($count > 0)
                <span @class([
                    'inline-flex min-w-[1.125rem] justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold text-white',
                    'bg-amber-500' => $key !== BankClearingTabRegistry::FILTER_ALL || $count > 0,
                ])>{{ $count }}</span>
            @endif
        </button>
    @endforeach
</div>