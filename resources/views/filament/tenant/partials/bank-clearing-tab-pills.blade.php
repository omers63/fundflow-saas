@php
use App\Filament\Tenant\Support\BankClearingTabRegistry;

$tabs = $this->getBankClearingTabs();
$bankFileCount = $this->getBankFileQueueCount();
$operationsCount = $this->getOperationsQueueCount();
$queueCount = $this->getOpenQueueCount();
@endphp

<div class="ff-tenant-tab-pills ff-bank-clearing-tab-pills mb-4 flex flex-wrap gap-2">
    @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setBankTab('{{ $key }}')" @class([
            'ff-tenant-tab-pills__item',
            'ff-tenant-tab-pills__item--active' => ($bankTab ?? BankClearingTabRegistry::TAB_QUEUE) === $key,
        ])>
                <span class="inline-flex items-center gap-1.5">
                    <x-ff-tab-pill-label :label="$label" :key="$key" />
                    @if ($key === BankClearingTabRegistry::TAB_QUEUE && $queueCount > 0)
                        <span
                            class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $queueCount }}</span>
                    @endif
                </span>
            </button>
    @endforeach
</div>