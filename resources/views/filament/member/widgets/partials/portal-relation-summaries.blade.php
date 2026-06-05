@props(['summaries'])

@if (count($summaries) > 0)
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($summaries as $card)
            @php
                $accentBar = [
                    'teal' => 'bg-teal-500',
                    'indigo' => 'bg-indigo-500',
                    'violet' => 'bg-violet-500',
                    'emerald' => 'bg-emerald-500',
                    'sky' => 'bg-sky-500',
                    'amber' => 'bg-amber-500',
                    'rose' => 'bg-rose-500',
                ];
                $bar = $accentBar[$card['accent']] ?? 'bg-gray-400';
            @endphp
            <a href="{{ $card['url'] ?? '#' }}" @class([
                'ff-member-stat-card relative block min-w-0 overflow-hidden rounded-xl border border-gray-200/80 px-3 py-2.5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700',
                'pointer-events-none opacity-60' => empty($card['url']),
            ])
                data-accent="{{ $card['accent'] ?? 'slate' }}">
                <div class="absolute inset-y-0 left-0 w-0.5 {{ $bar }}"></div>
                <div class="flex items-center gap-1.5 pl-1">
                    <x-dynamic-component :component="$card['icon']" class="h-3.5 w-3.5 text-gray-400" />
                    <x-ff-stat-line :text="ui_label($card['label'])"
                        class="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500" />
                </div>
                <x-ff-stat-line :text="(string) $card['value']"
                    class="mt-1 truncate pl-1 text-sm font-bold text-gray-900 dark:text-white" />
                @if (filled($card['hint'] ?? null))
                    <x-ff-stat-line :text="ui_label($card['hint'])" class="truncate pl-1 text-[10px] text-gray-400" />
                @endif
            </a>
        @endforeach
    </div>
@endif