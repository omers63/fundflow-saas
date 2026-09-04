{{--
  Compact chip row.
  $chips: list of [label, url?, tone?]
--}}
@php
    $chips = $chips ?? [];
@endphp

@if ($chips !== [])
    <div class="ff-ops-overview__chips flex min-w-0 flex-wrap items-center gap-2">
        @foreach ($chips as $chip)
            @php
                $tone = $chip['tone'] ?? 'gray';
                $url = $chip['url'] ?? null;
                $tag = filled($url) ? 'a' : 'span';
                $toneClass = match ($tone) {
                    'success', 'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                    'warning', 'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                    'danger', 'rose' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
                    'violet' => 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
                    'sky', 'info' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
                    default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                };
            @endphp
            <{{ $tag }}
                @if ($tag === 'a') href="{{ $url }}" @endif
                @class([
                    'inline-flex max-w-full items-center rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    $toneClass,
                    'transition hover:opacity-90' => $tag === 'a',
                ])
            >
                <span class="truncate">{!! \App\Filament\Support\MoneyDisplay::markupForDisplay($chip['label'] ?? '') !!}</span>
            </{{ $tag }}>
        @endforeach
    </div>
@endif
