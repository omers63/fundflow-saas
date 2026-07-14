@php
$tabs = $this->getAuditSystemTabs();
@endphp

<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setSideTab('{{ $key }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $this->sideTab === $key,
        ])>
                <x-ff-tab-pill-label :label="$label" :key="$key" />
            </button>
    @endforeach
</div>

@if ($this->tenantUserIsAdmin())
    <div class="mb-4">
        <a href="{{ \App\Filament\Tenant\Pages\MessagesInboxPage::getUrl() }}"
            class="inline-flex items-center gap-2 text-xs font-semibold text-sky-700 no-underline hover:underline dark:text-sky-300">
            <x-heroicon-o-chat-bubble-left-right class="h-4 w-4 shrink-0" />
            {{ __('Messages inbox') }}
        </a>
    </div>
@endif