<div>
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['cash' => __('Cash'), 'fund' => __('Fund'), 'loan' => __('Loans')] as $key => $label)
            <x-filament::button :color="$ledgerTab === $key ? 'primary' : 'gray'" wire:click="setLedgerTab('{{ $key }}')"
                size="sm">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    {{ $this->table }}
</div>