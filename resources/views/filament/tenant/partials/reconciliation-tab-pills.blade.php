@php
    $tabs = $this->getReconciliationTabs();
    $openCount = $this->getOpenExceptionCount();
@endphp

<div class="ff-tenant-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
        <button type="button" wire:click="setSideTab('{{ $key }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => $this->sideTab === $key,
        ])>
            <span class="inline-flex items-center gap-1.5">
                {{ $label }}
                @if ($key === 'exceptions' && $openCount > 0)
                    <span
                        class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $openCount }}</span>
                @endif
            </span>
        </button>
    @endforeach
</div>