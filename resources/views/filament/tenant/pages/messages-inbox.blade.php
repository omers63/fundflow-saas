<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('Member conversations') }}</x-slot>
        <x-slot name="description">
            {{ __('Communicate with members individually or in bulk. Opening a conversation marks their messages to you as read.') }}
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>