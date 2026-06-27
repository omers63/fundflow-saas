@if ($this->advancedUiAvailable())
    <div class="ff-advanced-ui-toggle flex shrink-0 items-center gap-2">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('View') }}</span>
        <div class="ff-tenant-tab-pills flex gap-1">
            <button type="button" wire:click="setAdvancedUi(false)" @class([
                'ff-tenant-tab-pills__item px-2.5 py-1 text-xs',
                'ff-tenant-tab-pills__item--active' => !$this->advancedUi,
            ])>
                {{ __('Simple') }}
            </button>
            <button type="button" wire:click="setAdvancedUi(true)" @class([
                'ff-tenant-tab-pills__item px-2.5 py-1 text-xs',
                'ff-tenant-tab-pills__item--active' => $this->advancedUi,
            ])>
                {{ __('Advanced') }}
            </button>
        </div>
    </div>
@endif