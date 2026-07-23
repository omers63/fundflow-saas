@php
    use App\Filament\Tenant\Support\CommunicationsTabRegistry;

    $activeTab = $activeTab ?? CommunicationsTabRegistry::TAB_INBOX;
@endphp

<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach (CommunicationsTabRegistry::tabs() as $tabKey => $tabLabel)
        <a href="{{ CommunicationsTabRegistry::url($tabKey) }}" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $activeTab === $tabKey,
        ])>
            <x-ff-tab-pill-label :label="$tabLabel" :key="$tabKey" />
        </a>
    @endforeach
</div>
