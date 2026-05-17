<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button :color="$this->delinquencyTab === 'installments' ? 'danger' : 'gray'"
            wire:click="setDelinquencyTab('installments')" icon="heroicon-o-calendar-days">
            {{ __('Overdue installments') }}
        </x-filament::button>
        <x-filament::button :color="$this->delinquencyTab === 'contributions' ? 'warning' : 'gray'"
            wire:click="setDelinquencyTab('contributions')" icon="heroicon-o-banknotes">
            {{ __('Contribution arrears') }}
        </x-filament::button>
        <x-filament::button :color="$this->delinquencyTab === 'guarantor' ? 'primary' : 'gray'"
            wire:click="setDelinquencyTab('guarantor')" icon="heroicon-o-shield-exclamation">
            {{ __('Guarantor exposure') }}
        </x-filament::button>
    </div>

    <div wire:key="delinquency-table-{{ $this->delinquencyTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
