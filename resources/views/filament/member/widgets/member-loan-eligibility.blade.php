<x-filament-widgets::widget class="ff-member-loan-eligibility-widget">
    <x-member::notice tone="amber" :title="__('Not eligible for a loan')">
        @if (filled($reason))
            <p class="m-0">{{ $reason }}</p>
        @endif
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if ($hasPending)
                <x-member::chip variant="amber">{{ __('Review pending') }}</x-member::chip>
            @elseif ($canRequest)
                <x-filament::button wire:click="mountAction('requestEligibilityOverride')" color="warning" size="sm">
                    {{ __('Request eligibility review') }}
                </x-filament::button>
            @endif
        </div>
    </x-member::notice>

    <x-filament-actions::modals />
</x-filament-widgets::widget>