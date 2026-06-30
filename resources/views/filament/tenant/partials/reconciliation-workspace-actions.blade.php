@props([
    'class' => '',
])

<div @class(['flex flex-wrap items-center justify-between gap-3', $class])>
    @if ($this->advancedUi)
        @include('filament.tenant.partials.audit-system.workspace-actions', [
            'class' => 'min-w-0 flex-1',
        ])
    @else
        <div class="ff-audit-workspace-actions min-w-0 flex flex-1 flex-wrap items-center gap-2">
            @if (auth('tenant')->user()?->is_admin)
                <button
                    type="button"
                    wire:click="runCheckNow"
                    wire:loading.attr="disabled"
                    wire:target="runCheckNow"
                    class="ff-tenant-btn inline-flex items-center gap-2 bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
                >
                    <x-heroicon-o-play class="h-4 w-4 shrink-0" wire:loading.remove wire:target="runCheckNow" />
                    <span
                        class="inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-white/30 border-t-white"
                        wire:loading
                        wire:target="runCheckNow"
                        aria-hidden="true"
                    ></span>
                    <span wire:loading.remove wire:target="runCheckNow">{{ __('Run check now') }}</span>
                    <span wire:loading wire:target="runCheckNow">{{ __('Running reconciliation checks…') }}</span>
                </button>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Uses bank settings from System → Settings when configured.') }}
                </p>
            @endif
        </div>

        @if (method_exists($this, 'advancedUiAvailable'))
            @include('filament.tenant.partials.advanced-ui-toggle')
        @endif
    @endif
</div>
