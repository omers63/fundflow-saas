<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ([
            'all' => __('All'),
            'emergency' => __('Emergency'),
            'standard' => __('Standard'),
            'partial' => __('Partial'),
        ] as $kind => $label)
        <button type="button" wire:click="setQueueKind('{{ $kind }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $queueKind === $kind,
        ])>
                {{ $label }}
            </button>
    @endforeach
</div>
