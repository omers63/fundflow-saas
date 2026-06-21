@php
    use App\Filament\Member\Resources\MyContributions\MyContributionResource;
    use App\Filament\Member\Resources\MyMessages\MyMessageResource;
    use App\Filament\Member\Support\MemberNavigation;
    use App\Services\ContributionCycleService;

    [$cycleMonth, $cycleYear] = app(ContributionCycleService::class)->currentOpenPeriod();
    $cycleLabel = app(ContributionCycleService::class)->periodLabel($cycleMonth, $cycleYear);
    $unreadMessages = MemberNavigation::unreadAdminMessageCount();
    $showLanguageSwitch = count(\BezhanSalleh\LanguageSwitch\LanguageSwitch::make()->getLocales()) > 1;
@endphp

<div class="ff-portal-topbar-shortcuts me-1 flex shrink-0 items-center gap-2">
    <div class="hidden shrink-0 items-center gap-2 sm:flex">
        <a href="{{ MyContributionResource::getUrl('index') }}"
            class="ff-portal-topbar-chip inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-violet-800 dark:text-violet-200">
            <x-heroicon-o-calendar-days class="h-4 w-4 shrink-0" />
            <span>{{ __('Cycle: :label', ['label' => $cycleLabel]) }}</span>
        </a>

        <a href="{{ MyMessageResource::getUrl('index') }}"
            class="ff-portal-topbar-chip inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200">
            <x-heroicon-o-chat-bubble-left-right class="h-4 w-4 shrink-0" />
            <span>{{ __('Messages') }}</span>
            @if ($unreadMessages > 0)
                <span
                    class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold text-white">
                    {{ $unreadMessages }}
                </span>
            @endif
        </a>
    </div>

    @if ($showLanguageSwitch)
        <div class="ff-portal-topbar-locale shrink-0">
            <livewire:tenant-topbar-language-switch key="fls-member-topbar" />
        </div>
    @endif
</div>