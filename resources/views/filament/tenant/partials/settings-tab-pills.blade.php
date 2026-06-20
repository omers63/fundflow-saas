@php
    $tabs = $this->getSettingsTabs();
    $active = request()->query('settingsTab', 'general::tab');
@endphp

<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
        <a href="{{ \App\Filament\Tenant\Support\SettingsTabRegistry::url($key) }}" @class([
            'ff-tenant-tab-pills__item no-underline',
            'ff-tenant-tab-pills__item--active' => $active === $key,
        ])>
            {{ $label }}
        </a>
    @endforeach
</div>