@if ($this->advancedUiAvailable())
    <div class="ff-advanced-ui-toggle flex shrink-0 items-center gap-2">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('View') }}</span>
        <div class="ff-tenant-tab-pills flex gap-1">
            <button type="button" wire:click="setAdvancedUi(false)" wire:target="setAdvancedUi" @class([
        'ff-tenant-tab-pills__item px-2.5 py-1 text-xs',
        'ff-tenant-tab-pills__item--active' => !$this->advancedUi,
    ])>
                <x-ff-tab-pill-label :label="__('Simple')" key="simple" />
                </button>
                <button type="button" wire:click="setAdvancedUi(true)" wire:target="setAdvancedUi" @class([
                    'ff-tenant-tab-pills__item px-2.5 py-1 text-xs',
                    'ff-tenant-tab-pills__item--active' => $this->advancedUi,
                ])>
                <x-ff-tab-pill-label :label="__('Advanced')" key="advanced" />
            </button>
        </div>
    </div>
@endif