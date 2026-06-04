<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button :color="$emiTab === 'collect' ? 'primary' : 'gray'" wire:click="setEmiTab('collect')"
            size="sm">
            {{ __('To collect') }}
        </x-filament::button>
        <x-filament::button :color="$emiTab === 'collected' ? 'primary' : 'gray'" wire:click="setEmiTab('collected')"
            size="sm">
            {{ __('Collected') }}
        </x-filament::button>
    </div>

    <div wire:key="emi-collection-table-{{ $emiTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>