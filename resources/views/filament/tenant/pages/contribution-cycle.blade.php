<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button
            :color="$this->contributionPeriodTab === 'pending' ? 'primary' : 'gray'"
            wire:click="setContributionTab('pending')"
        >
            {{ __('Pending') }}
        </x-filament::button>
        <x-filament::button
            :color="$this->contributionPeriodTab === 'paid' ? 'primary' : 'gray'"
            wire:click="setContributionTab('paid')"
        >
            {{ __('Paid') }}
        </x-filament::button>
    </div>

    <div wire:key="contribution-cycle-table-{{ $this->contributionPeriodTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
