@php
    $isAdmin = $this->tenantUserIsAdmin();
@endphp

@if ($isAdmin)
    <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50/80 px-4 py-4 dark:border-white/10 dark:bg-white/5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-3">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $loggingTitle }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $loggingDescription }}</p>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox"
                        class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-white/20 dark:bg-gray-900"
                        wire:model.live="{{ $toggleProperty }}" />
                    <span>{{ $toggleLabel }}</span>
                </label>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Stored rows: :count', ['count' => number_format($rowCount)]) }}
                </p>
            </div>

            <div class="shrink-0">
                <x-filament::button color="danger" outlined wire:click="{{ $truncateAction }}"
                    wire:confirm="{{ $truncateConfirm }}">
                    {{ $truncateLabel }}
                </x-filament::button>
            </div>
        </div>
    </div>
@endif