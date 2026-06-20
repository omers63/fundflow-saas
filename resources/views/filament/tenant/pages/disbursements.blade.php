<x-filament-panels::page>
    @php($stats = $this->summaryStats())

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Pending'), 'value' => (string) $stats['pending_count']],
            ['label' => __('Committed'), 'value' => $stats['committed']],
            ['label' => __('Disbursed'), 'value' => $stats['disbursed']],
            ['label' => __('Remaining'), 'value' => $stats['remaining']],
        ] as $stat)
            <div class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm dark:border-white/10 dark:bg-slate-800">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500">{{ $stat['label'] }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $stat['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
        @foreach ([
            'pending' => __('Pending'),
            'partial' => __('Partial'),
            'complete' => __('Fully disbursed'),
        ] as $tab => $label)
            <button type="button" wire:click="setDisbursementTab('{{ $tab }}')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $disbursementTab === $tab,
            ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
