@php
    use App\Filament\Tenant\Pages\MessagesInboxPage;
    use App\Filament\Tenant\Resources\Contributions\ContributionResource;
    use App\Services\ContributionCycleService;
    use App\Services\Tenant\DirectMessagingService;

    $admin = auth('tenant')->user();
    $isAdmin = $admin?->is_admin === true;
    [$cycleMonth, $cycleYear] = app(ContributionCycleService::class)->currentOpenPeriod();
    $cycleLabel = app(ContributionCycleService::class)->periodLabel($cycleMonth, $cycleYear);
    $unreadMessages = $isAdmin && $admin !== null
        ? app(DirectMessagingService::class)->unreadCountForAdmin((int) $admin->id)
        : 0;
    $showLanguageSwitch = count(\BezhanSalleh\LanguageSwitch\LanguageSwitch::make()->getLocales()) > 1;
@endphp

<div class="ff-tenant-topbar-shortcuts me-1 hidden shrink-0 items-center gap-2 sm:flex">
    <a href="{{ ContributionResource::getUrl('index') }}"
        class="ff-tenant-topbar-chip inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-sky-800 dark:text-sky-200">
        <x-heroicon-o-calendar-days class="h-4 w-4 shrink-0" />
        <span>{{ __('Cycle: :label', ['label' => $cycleLabel]) }}</span>
    </a>

    @if ($isAdmin)
        <a href="{{ MessagesInboxPage::getUrl() }}"
            class="ff-tenant-topbar-chip inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200">
            <x-heroicon-o-chat-bubble-left-right class="h-4 w-4 shrink-0" />
            <span>{{ __('Messages') }}</span>
            @if ($unreadMessages > 0)
                <span
                    class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                    {{ $unreadMessages }}
                </span>
            @endif
        </a>
    @endif

    @if ($showLanguageSwitch)
        <div class="ff-tenant-topbar-locale shrink-0">
            <livewire:tenant-topbar-language-switch key="fls-tenant-topbar" />
        </div>
    @endif
</div>