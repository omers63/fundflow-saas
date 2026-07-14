@php
$tabs = [
    'support' => __('Support'),
    'membership' => __('Membership'),
];
@endphp

<div class="ff-member-help-requests space-y-4">
    <div class="ff-member-tab-bar flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setRequestsSection('{{ $key }}')" @class([
                'ff-member-tab-bar__item rounded-t-lg px-3 py-1.5 text-sm font-semibold transition',
                'border-b-2 border-primary-600 text-primary-700 dark:text-primary-400' => $requestsSection === $key,
                'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200' => $requestsSection !== $key,
            ])>
                        <x-ff-tab-pill-label :label="$label" :key="$key" />
                        @if ($key === 'support' && $openSupportCount > 0)
                            <span
                                class="ms-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ $openSupportCount }}
                            </span>
                        @endif
                        @if ($key === 'membership' && $pendingMembershipCount > 0)
                            <span
                                class="ms-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ $pendingMembershipCount }}
                            </span>
                        @endif
                    </button>
        @endforeach
    </div>

    @if ($requestsSection === 'support')
        @livewire(\App\Filament\Member\Widgets\MySupportRequestsTableWidget::class, key('member-help-support'))
    @else
        @if ($showDependentsLink)
            <x-member::notice tone="blue">
                <p class="m-0">
                    {{ __('To add or remove dependents, go to') }}
                    <a href="{{ $dependentsUrl }}" wire:navigate class="font-semibold underline">{{ __('Dependents') }}</a>.
                </p>
            </x-member::notice>
        @endif

        @livewire(\App\Filament\Member\Widgets\MyMemberRequestsTableWidget::class, key('member-help-membership'))
    @endif
</div>