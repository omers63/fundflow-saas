@php
    use App\Filament\Support\UiLabelIcons;
@endphp

<div>
    <div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
        @foreach (['cash' => __('Cash'), 'fund' => __('Fund'), 'loan' => __('Loans')] as $key => $label)
            <button type="button" wire:click="setLedgerTab('{{ $key }}')" @class([
                'ff-tenant-tab-pills__item',
                'ff-tenant-tab-pills__item--active' => $ledgerTab === $key,
            ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{ $this->table }}
</div>