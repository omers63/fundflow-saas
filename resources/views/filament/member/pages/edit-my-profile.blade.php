<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit">
                {{ __('Save changes') }}
            </x-filament::button>
            <x-filament::button tag="a" :href="\App\Filament\Member\Pages\MyProfilePage::getUrl()" color="gray">
                {{ __('Cancel') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
