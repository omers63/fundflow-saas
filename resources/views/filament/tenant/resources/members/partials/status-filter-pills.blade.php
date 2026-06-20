<div class="mb-4 space-y-3">
    <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
        @foreach ($tabs as $tab)
            <a href="{{ \App\Filament\Tenant\Resources\Members\MemberResource::listTabUrl($tab['key']) }}" @class([
                'ff-tenant-tab-pills__item no-underline',
                'ff-tenant-tab-pills__item--active' => $activeTab === $tab['key'],
                'ff-tenant-tab-pills__item--violet' => $activeTab !== $tab['key'] && $tab['variant'] === 'violet' && $tab['count'] > 0,
                'ff-tenant-tab-pills__item--danger' => $activeTab !== $tab['key'] && $tab['variant'] === 'danger' && $tab['count'] > 0,
                'ff-tenant-tab-pills__item--warning' => $activeTab !== $tab['key'] && $tab['variant'] === 'warning' && $tab['count'] > 0,
            ])>
                {{ $tab['label'] }}
                @if ($tab['count'] > 0)
                    <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $activeTab === $tab['key'],
                        'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200' => $activeTab !== $tab['key'] && $tab['variant'] === 'violet',
                        'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $activeTab !== $tab['key'] && $tab['variant'] === 'danger',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $activeTab !== $tab['key'] && $tab['variant'] === 'warning',
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $activeTab !== $tab['key'] && $tab['variant'] === 'neutral',
                    ])>{{ $tab['count'] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    @if ($activeTab === 'migration_pending')
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Imported members with opening balances who still have unresolved contribution arrears before full go-live clearance.') }}
        </p>
    @endif
</div>