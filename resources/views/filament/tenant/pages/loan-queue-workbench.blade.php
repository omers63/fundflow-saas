<x-filament-panels::page>
    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
        @foreach ([
                        'needs_decision' => __('Needs decision'),
                        'ready_to_disburse' => __('Ready to disburse'),
                    ] as $tab => $label)
             <button type="button" wire:click="setQueueTab('{{ $tab }}')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $queueTab === $tab,
            ])>
                            {{ $label }}
                        </button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
