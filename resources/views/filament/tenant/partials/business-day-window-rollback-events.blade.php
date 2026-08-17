@php
    $fieldWrapperView = $getFieldWrapperView();
    $extraInputAttributeBag = $getExtraInputAttributeBag();
    $isDisabled = $isDisabled();
    $statePath = $getStatePath();
    $livewireKey = $getLivewireKey();
    $wireModelAttribute = $applyStateBindingModifiers('wire:model');
    $selected = array_map('strval', $getState() ?? []);
    $sections = $sections ?? [];
    $hasEvents = $sections !== [];
@endphp

<x-dynamic-component :component="$fieldWrapperView" :field="$field">
    <div x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('checkbox-list', 'filament/forms') }}"
        x-data="checkboxListFormComponent({
                    livewireId: @js($this->getId()),
                })" {{ $getExtraAlpineAttributeBag()->class(['fi-fo-checkbox-list ff-rollback-events']) }}>
        @if (!$hasEvents)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No activity after this date.') }}</p>
        @else
            @if (!$isDisabled)
                <div class="ff-rollback-events-toolbar">
                    <div x-cloak class="fi-fo-checkbox-list-actions" wire:key="{{ $livewireKey }}.actions">
                        <span x-show="! areAllCheckboxesChecked" x-on:click="toggleAllCheckboxes()"
                            wire:key="{{ $livewireKey }}.actions.select-all">
                            {{ $getAction('selectAll') }}
                        </span>
                        <span x-show="areAllCheckboxesChecked" x-on:click="toggleAllCheckboxes()"
                            wire:key="{{ $livewireKey }}.actions.deselect-all">
                            {{ $getAction('deselectAll') }}
                        </span>
                    </div>
                </div>
            @endif

            <div class="ff-rollback-events-list space-y-3">
                @foreach ($sections as $section)
                    @php
                        $eventIds = array_column($section['events'], 'id');
                        $selectedInSection = count(array_intersect($eventIds, $selected));
                    @endphp
                    <x-filament::section
                        compact
                        collapsible
                        :collapse-id="'ff-rollback-'.$section['key']"
                        :heading="$section['heading']"
                        :description="__(':selected of :total selected', [
                            'selected' => $selectedInSection,
                            'total' => count($section['events']),
                        ])"
                    >
                        <x-slot name="afterHeader">
                            <label class="ff-rollback-events-select-group">
                                <input type="checkbox" class="fi-checkbox-input" @checked($selectedInSection === count($eventIds))
                                    x-on:change="
                                                const ids = {{ \Illuminate\Support\Js::from($eventIds) }};
                                                const path = @js($statePath);
                                                let current = $wire.get(path) ?? [];
                                                if ($event.target.checked) {
                                                    current = [...new Set([...current, ...ids])];
                                                } else {
                                                    current = current.filter((id) => ! ids.includes(id));
                                                }
                                                $wire.set(path, current);
                                            " />
                                <span>{{ __('Select all') }}</span>
                            </label>
                        </x-slot>

                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                            <table class="w-full text-sm ff-rollback-events-table">
                                <thead>
                                    <tr class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                        <th class="w-10 py-2 px-3"></th>
                                        <th class="py-2 px-3 text-start font-medium">{{ __('Name') }}</th>
                                        <th class="py-2 px-3 text-start font-medium">{{ __('Detail') }}</th>
                                        <th class="py-2 px-3 text-end font-medium">{{ __('Amount') }}</th>
                                        <th class="py-2 px-3 text-start font-medium">{{ __('Date') }}</th>
                                        <th class="py-2 px-3 text-start font-medium">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['events'] as $event)
                                                    <tr class="ff-rollback-events-item border-b border-gray-100 dark:border-white/10"
                                                        wire:key="{{ $livewireKey }}.options.{{ $event['id'] }}">
                                                        <td class="py-2 px-3 align-middle">
                                                            <input type="checkbox" value="{{ $event['id'] }}" {{
                                        $extraInputAttributeBag
                                            ->merge([
                                                'disabled' => $isDisabled,
                                                $wireModelAttribute => $statePath,
                                                'x-on:change' => 'checkIfAllCheckboxesAreChecked()',
                                            ], escape: false)
                                            ->class(['fi-checkbox-input'])
                                                                            }} />
                                                        </td>
                                                        <td class="py-2 px-3 align-middle font-medium text-gray-900 dark:text-white">
                                                            {{ $event['title'] }}</td>
                                                        <td class="py-2 px-3 align-middle text-gray-600 dark:text-gray-300">
                                                            {{ $event['detail'] ?: '—' }}</td>
                                                        <td class="py-2 px-3 align-middle text-end tabular-nums text-gray-900 dark:text-white">
                                                            {{ $event['amount'] ?: '—' }}</td>
                                                        <td class="py-2 px-3 align-middle text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                                            {{ $event['date'] ?: '—' }}</td>
                                                        <td class="py-2 px-3 align-middle capitalize text-gray-600 dark:text-gray-300">
                                                            {{ $event['status'] ?: '—' }}</td>
                                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </div>
</x-dynamic-component>