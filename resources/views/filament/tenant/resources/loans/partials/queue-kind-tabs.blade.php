@php
    use App\Filament\Tenant\Resources\Loans\Pages\ListLoanQueue;

    $queueKind = $queueKind ?? null;

    if ($queueKind === null && isset($schemaComponent)) {
        $livewire = $schemaComponent->getLivewire();

        if ($livewire instanceof ListLoanQueue) {
            $queueKind = $livewire->queueKind;
        }
    }

    $queueKind = in_array($queueKind, ['all', 'emergency', 'standard', 'partial'], true)
        ? $queueKind
        : 'all';
@endphp

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
