@php
    $kpis = $this->getQueueInsightKpis();
@endphp

@if ($kpis !== [])
    <div class="ff-bank-clearing-kpis grid grid-cols-2 gap-3 xl:grid-cols-4">
        @foreach ($kpis as $kpi)
            <a href="{{ $kpi['url'] }}"
                class="ff-bank-clearing-kpi rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:border-sky-200 hover:bg-sky-50/40 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-sky-800/40 dark:hover:bg-sky-950/20">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $kpi['label'] }}</p>
                <p @class([
                    'mt-1 text-xl font-bold tabular-nums leading-none',
                    'text-amber-600 dark:text-amber-400' => ($kpi['accent'] ?? '') === 'amber',
                    'text-emerald-600 dark:text-emerald-400' => ($kpi['accent'] ?? '') === 'emerald',
                    'text-rose-600 dark:text-rose-400' => ($kpi['accent'] ?? '') === 'rose',
                    'text-sky-600 dark:text-sky-400' => ($kpi['accent'] ?? '') === 'sky',
                    'text-gray-900 dark:text-white' => !in_array($kpi['accent'] ?? '', ['amber', 'emerald', 'rose', 'sky'], true),
                ])>{{ $kpi['value'] }}</p>
                <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $kpi['sub'] }}</p>
            </a>
        @endforeach
    </div>
@endif