@props([
    'class' => '',
])

@php
    $canRun = auth('tenant')->user()?->is_admin === true;
@endphp

@if ($canRun)
    <div @class(['ff-recon-run-toolbar space-y-3 mb-4', $class])>
        <div class="ff-recon-run-actions flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="queueRealtimeReconciliation"
                wire:loading.attr="disabled"
                wire:target="queueRealtimeReconciliation,queueExceptionQueueRecheck,queueDailySnapshot,queueMonthlySnapshot"
                class="fi-btn fi-size-md fi-btn-color-primary fi-color fi-color-primary relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold outline-none transition duration-75 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 inline-grid shadow-sm bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400"
            >
                <x-heroicon-o-play class="h-4 w-4" />
                <span>{{ __('Run check now') }}</span>
            </button>

            <button
                type="button"
                wire:click="queueExceptionQueueRecheck"
                wire:confirm="{{ __('Re-run exception checks now? This rebuilds the exception queue (nightly batch) and does not store a snapshot.') }}"
                wire:loading.attr="disabled"
                wire:target="queueRealtimeReconciliation,queueExceptionQueueRecheck,queueDailySnapshot,queueMonthlySnapshot"
                class="fi-btn fi-size-md fi-btn-color-gray fi-color fi-color-gray relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold outline-none transition duration-75 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 inline-grid shadow-sm bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
            >
                <x-heroicon-o-arrow-path class="h-4 w-4" />
                <span>{{ __('Exception queue re-check') }}</span>
            </button>

            <button
                type="button"
                wire:click="queueDailySnapshot"
                wire:confirm="{{ __('Record a daily reconciliation snapshot using yesterday’s window and current ledger checks?') }}"
                wire:loading.attr="disabled"
                wire:target="queueRealtimeReconciliation,queueExceptionQueueRecheck,queueDailySnapshot,queueMonthlySnapshot"
                class="fi-btn fi-size-md fi-btn-color-gray fi-color fi-color-gray relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold outline-none transition duration-75 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 inline-grid shadow-sm bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
            >
                <x-heroicon-o-calendar-days class="h-4 w-4" />
                <span>{{ __('Daily snapshot') }}</span>
            </button>

            <button
                type="button"
                wire:click="queueMonthlySnapshot"
                wire:confirm="{{ __('Record a monthly reconciliation snapshot using the previous calendar month and current ledger checks?') }}"
                wire:loading.attr="disabled"
                wire:target="queueRealtimeReconciliation,queueExceptionQueueRecheck,queueDailySnapshot,queueMonthlySnapshot"
                class="fi-btn fi-size-md fi-btn-color-gray fi-color fi-color-gray relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold outline-none transition duration-75 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 inline-grid shadow-sm bg-white text-gray-950 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10"
            >
                <x-heroicon-o-calendar class="h-4 w-4" />
                <span>{{ __('Monthly snapshot') }}</span>
            </button>

            <span
                wire:loading
                wire:target="queueRealtimeReconciliation,queueExceptionQueueRecheck,queueDailySnapshot,queueMonthlySnapshot"
                class="text-xs font-medium text-sky-700 dark:text-sky-300"
            >
                {{ __('Queueing…') }}
            </span>
        </div>

        @if (filled($this->reconciliationRunFeedback))
            <div
                wire:key="recon-run-feedback-{{ $this->reconciliationRunToken ?? md5($this->reconciliationRunFeedback) }}"
                @if (filled($this->reconciliationRunToken))
                    wire:poll.3s="refreshReconciliationRunStatus"
                @endif
                role="status"
                class="flex flex-wrap items-start gap-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-950 shadow-sm dark:border-emerald-700/60 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                <x-heroicon-o-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-semibold">{{ __('Reconciliation queued') }}</p>
                    <p class="mt-0.5 text-xs">{{ $this->reconciliationRunFeedback }}</p>
                </div>
                <button
                    type="button"
                    wire:click="dismissReconciliationRunFeedback"
                    class="ff-tenant-btn shrink-0 px-2 py-1 text-xs"
                >
                    {{ __('Dismiss') }}
                </button>
            </div>
        @endif
    </div>
@endif
