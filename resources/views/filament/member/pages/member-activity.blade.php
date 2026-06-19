<x-filament-panels::page>
    <div class="ff-member-activity space-y-4">
        <div class="ff-member-filter-chips flex flex-wrap gap-2">
            @foreach ($filters as $chip)
                <button type="button" wire:click="setFilter('{{ $chip['key'] }}')" @class([
                    'ff-member-filter-chip rounded-full px-3 py-1.5 text-sm font-semibold transition',
                    'ff-member-filter-chip--active' => $activeFilter === $chip['key'],
                ])>
                    {{ $chip['label'] }}
                </button>
            @endforeach
        </div>

        <x-member::panel :title="__('Export activity')">
            <p class="mb-3 text-sm text-gray-600">
                {{ __('Download a CSV of your transactions for a date range.') }}
            </p>
            <form method="GET" action="{{ $exportUrl }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        for="activity-from">
                        {{ __('From') }}
                    </label>
                    <input id="activity-from" type="date" name="from" required
                        class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                </div>
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500"
                        for="activity-to">
                        {{ __('To') }}
                    </label>
                    <input id="activity-to" type="date" name="to" required
                        class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm" />
                </div>
                <input type="hidden" name="filter" value="{{ $activeFilter }}" />
                <x-filament::button type="submit" color="gray" size="sm">
                    {{ __('Download CSV') }}
                </x-filament::button>
            </form>
        </x-member::panel>

        @livewire(\App\Filament\Member\Widgets\MemberActivityTableWidget::class, ['filter' => $activeFilter], key('member-activity-table-' . $activeFilter))
    </div>
</x-filament-panels::page>