<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('Exports include your profile, balances, contributions, and loans. Closure anonymizes personal fields after admin approval; ledgers stay intact.') }}
        </p>

        @if ($lastExportId)
            <button type="button" wire:click="downloadExport({{ $lastExportId }})" class="text-sm font-medium text-primary-600 hover:underline">
                {{ __('Download latest export') }}
            </button>
        @endif

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold">{{ __('Recent privacy requests') }}</h3>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($requests as $request)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span>{{ \App\Models\Tenant\MemberPrivacyRequest::typeLabels()[$request->type] ?? $request->type }}</span>
                        <span class="text-xs text-gray-500">{{ $request->status }} · {{ $request->created_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-gray-500">{{ __('No privacy requests yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    @include('filament.tenant.partials.page-workspace-action-modals')
</x-filament-panels::page>
