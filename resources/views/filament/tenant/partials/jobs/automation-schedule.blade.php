<form wire:submit="saveAutomationSchedule" wire:key="automation-schedule-form">
    {{ $this->automationScheduleForm }}

    <div class="mt-6 flex justify-end border-t border-gray-100 pt-4 dark:border-white/10">
        <x-filament::button type="submit" size="lg">
            {{ __('Save schedule') }}
        </x-filament::button>
    </div>
</form>
