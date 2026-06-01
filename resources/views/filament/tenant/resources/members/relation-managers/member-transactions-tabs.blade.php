@php
    use App\Filament\Support\UiLabelIcons;
@endphp

<div>
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['cash' => __('Cash'), 'fund' => __('Fund'), 'loan' => __('Loans')] as $key => $label)
            <x-filament::button :color="$ledgerTab === $key ? 'primary' : 'gray'" wire:click="setLedgerTab('{{ $key }}')"
                size="sm" :icon="UiLabelIcons::forKey($key)">
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    {{ $this->table }}
</div>