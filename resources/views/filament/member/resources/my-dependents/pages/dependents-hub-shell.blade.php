@php
$tabs = [
    'dependents' => __('Dependents'),
    'requests' => __('Requests'),
];
@endphp

<div class="ff-member-dependents-hub mb-4 space-y-4">
    <div class="ff-member-tab-bar flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        wire:click="setActiveSection('{{ $key }}')"
                        @class([
                'ff-member-tab-bar__item rounded-t-lg px-3 py-1.5 text-sm font-semibold transition',
                'border-b-2 border-primary-600 text-primary-700 dark:text-primary-400' => $activeSection === $key,
                'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $activeSection !== $key,
            ])
                    >
                        <x-ff-tab-pill-label :label="$label" :key="$key" />
                        @if ($key === 'dependents' && $dependentsCount > 0)
                            <span class="ms-1 text-xs font-normal text-gray-500">({{ $dependentsCount }})</span>
                        @endif
                        @if ($key === 'requests' && $pendingRequestsCount > 0)
                            <span
                                class="ms-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ $pendingRequestsCount }}
                            </span>
                        @endif
                    </button>
        @endforeach
    </div>

    @if ($activeSection === 'dependents')
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Click a row to open that dependent’s portal. Use row actions to manage funding or transfer cash.') }}
        </p>
    @endif
</div>
