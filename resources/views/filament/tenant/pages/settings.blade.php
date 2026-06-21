<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Organisation settings') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Configure currency, contribution rules, loan policies, reconciliation, and tenant-wide defaults.') }}
            </p>
        </header>

        @include('filament.tenant.partials.settings-tab-pills')

        <form wire:submit="save" wire:key="settings-form-{{ $settingsTab }}">
            {{ $this->form }}

            <div class="mt-6 flex justify-end border-t border-gray-100 pt-4 dark:border-white/10">
                <x-filament::button type="submit" size="lg">
                    {{ __('Save settings') }}
                </x-filament::button>
            </div>
        </form>
    </section>
</x-filament-panels::page>