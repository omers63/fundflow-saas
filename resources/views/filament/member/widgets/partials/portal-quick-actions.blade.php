@props(['actions' => []])

<div class="ff-member-quick-actions">
    <h3 class="ff-member-quick-actions__heading">{{ __('Quick actions') }}</h3>
    <div class="ff-member-quick-actions__grid">
        @foreach ($actions as $i => $action)
            @if ($action['visible'] ?? false)
                <a href="{{ $action['url'] }}"
                    class="ff-dashboard-action group relative isolate min-h-[5.25rem] overflow-hidden rounded-xl p-3 text-white shadow-sm ring-1 ring-black/10 transition hover:-translate-y-0.5 hover:shadow-lg"
                    style="animation: ff-stat-in 0.4s ease-out {{ 0.04 + ($i * 0.04) }}s forwards">
                    <div @class(['ff-dashboard-action__bg', 'ff-dashboard-action__bg--' . ($action['tone'] ?? 'accounts')])
                        aria-hidden="true"></div>
                    <div class="relative z-10 flex flex-col gap-1">
                        <div class="flex items-start justify-between gap-1">
                            <x-dynamic-component :component="$action['icon']"
                                class="h-5 w-5 shrink-0 text-white drop-shadow-sm" />
                            @if (filled($action['badge'] ?? null))
                                <span
                                    class="rounded-full bg-white/30 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-white shadow-sm ring-1 ring-white/40">
                                    {{ $action['badge'] }}
                                </span>
                            @endif
                        </div>
                        <span
                            class="text-xs font-semibold leading-tight text-white drop-shadow-sm">{{ $action['label'] }}</span>
                        @if (filled($action['subtitle'] ?? $action['description'] ?? null))
                            <span
                                class="line-clamp-2 text-[10px] leading-snug text-white/95 drop-shadow-sm">{{ $action['subtitle'] ?? $action['description'] }}</span>
                        @endif
                    </div>
                </a>
            @endif
        @endforeach
    </div>
</div>