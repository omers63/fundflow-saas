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

    @if (in_array($ledgerTab, ['cash', 'fund'], true) && auth('tenant')->user()?->is_admin && count($this->getTable()->getHeaderActions()) > 0)
        <div class="ff-ledger-header-actions mb-3 flex flex-wrap gap-2">
            @foreach ($this->getTable()->getHeaderActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</div>