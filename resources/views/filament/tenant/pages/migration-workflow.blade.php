<x-filament-panels::page>
    <div class="mb-4 flex flex-wrap gap-2">
        <x-filament::button :color="$this->migrationWorkflowTab === 'queue' ? 'primary' : 'gray'"
            wire:click="setMigrationTab('queue')">
            {{ __('In migration') }}
        </x-filament::button>
        <x-filament::button :color="$this->migrationWorkflowTab === 'stubs' ? 'primary' : 'gray'"
            wire:click="setMigrationTab('stubs')">
            {{ __('Open stubs') }}
        </x-filament::button>
        <x-filament::button :color="$this->migrationWorkflowTab === 'not_started' ? 'primary' : 'gray'"
            wire:click="setMigrationTab('not_started')">
            {{ __('Not started') }}
        </x-filament::button>
    </div>

    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Classify historical cycle stubs, post opening balances, apply settlements, then clear each member from the member profile (Migration menu) or from this page.') }}
    </p>

    <div wire:key="migration-workflow-table-{{ $this->migrationWorkflowTab }}">
        {{ $this->table }}
    </div>
</x-filament-panels::page>