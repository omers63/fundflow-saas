<x-filament-panels::page>
    <section
        class="rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <header class="mb-4 border-b border-gray-100 pb-4 dark:border-white/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Communications') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
        </header>

        @include('filament.tenant.partials.communications-tab-pills', ['activeTab' => $this->sideTab])

        <div class="min-w-0 space-y-6" wire:key="communications-{{ $this->sideTab }}">
            @if ($this->sideTab === 'inbox')
                <div
                    class="overflow-hidden rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-sky-800/40 dark:bg-slate-800 dark:text-gray-300">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ __('Member conversations') }}</p>
                    <p class="mt-1 text-xs leading-relaxed">
                        {{ __('Communicate with members individually or in bulk. Opening a conversation marks their messages to you as read.') }}
                    </p>
                </div>

                {{ $this->table }}
            @elseif ($this->sideTab === 'templates')
                @include('filament.tenant.pages.partials.communications-templates-workspace')
            @else
                {{ $this->table }}
            @endif
        </div>
    </section>
@include('filament.tenant.partials.page-workspace-action-modals')
</x-filament-panels::page>