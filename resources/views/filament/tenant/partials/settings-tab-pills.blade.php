@php
$tabs = $this->getSettingsTabs();
@endphp

<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setSettingsTab('{{ $key }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $this->settingsTab === $key,
        ])>
                <x-ff-tab-pill-label :label="$label" :key="$key" />
            </button>
    @endforeach
</div>
