@php
    /** @var array<string, mixed>|null $panel */
    $panel = $panel ?? null;
@endphp

@if (is_array($panel) && $panel !== [])
    @php
        $tone = (string) ($panel['tone'] ?? 'success');
        $defaultOpen = (bool) ($panel['default_open'] ?? false);
        $errors = is_array($panel['errors'] ?? null) ? $panel['errors'] : [];
        $stats = is_array($panel['stats'] ?? null) ? $panel['stats'] : [];
        $extra = is_array($panel['extra'] ?? null) ? $panel['extra'] : [];
        $errorsOpen = (bool) ($panel['errors_default_open'] ?? false);
        $border = match ($tone) {
            'danger' => 'border-danger-200 dark:border-danger-500/30',
            'warning' => 'border-amber-200 dark:border-amber-500/30',
            default => 'border-emerald-200 dark:border-emerald-500/30',
        };
        $badge = match ($tone) {
            'danger' => 'bg-danger-100 text-danger-800 dark:bg-danger-500/20 dark:text-danger-200',
            'warning' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
            default => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
        };
        $statusLabel = match ($tone) {
            'danger' => __('Failed'),
            'warning' => __('Warnings'),
            default => __('Succeeded'),
        };
    @endphp

    <details
        class="group rounded-xl border {{ $border }} bg-white shadow-sm dark:bg-gray-900/60"
        wire:key="{{ $panel['wire_key'] ?? 'legacy-migration-step-results' }}"
        @if ($defaultOpen) open @endif
    >
        <summary class="cursor-pointer list-none px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $badge }}">
                            {{ $statusLabel }}
                        </span>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ $panel['title'] ?? __('Import result') }}
                        </span>
                    </div>

                    @if (filled($panel['message'] ?? null))
                        <p class="line-clamp-2 text-xs text-gray-600 dark:text-gray-300">
                            {{ $panel['message'] }}
                        </p>
                    @elseif ($stats !== [])
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ collect($stats)->map(fn ($row) => ($row['label'] ?? '').': '.($row['value'] ?? '0'))->implode(' · ') }}
                        </p>
                    @else
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Expand for counts, stage, and row-level errors.') }}
                        </p>
                    @endif
                </div>

                <x-heroicon-m-chevron-down class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180" />
            </div>
        </summary>

        <div class="space-y-3 border-t border-gray-100 px-4 py-3 dark:border-white/10 sm:px-5">
            @if (filled($panel['message'] ?? null))
                <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm leading-relaxed text-gray-800 dark:bg-white/5 dark:text-gray-100">
                    {{ $panel['message'] }}
                </p>
            @endif

            @if (filled($panel['stage_label'] ?? null))
                <p class="text-xs text-gray-600 dark:text-gray-300">
                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ __('Stage') }}:</span>
                    {{ $panel['stage_label'] }}
                </p>
            @endif

            @if ($stats !== [])
                <ul class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($stats as $stat)
                        <li class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                            <span class="text-gray-500 dark:text-gray-400">{{ $stat['label'] ?? '' }}</span>
                            <span class="ms-1 font-semibold text-gray-900 dark:text-white">{{ $stat['value'] ?? 0 }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @foreach ($extra as $block)
                @if (filled($block['body'] ?? null))
                    <div class="rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                        @if (filled($block['title'] ?? null))
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $block['title'] }}
                            </p>
                        @endif
                        <p class="mt-1 text-xs leading-relaxed text-gray-700 dark:text-gray-200">{{ $block['body'] }}</p>
                    </div>
                @endif
            @endforeach

            @if (filled($panel['location'] ?? null))
                <details class="group/tech rounded-lg border border-gray-100 dark:border-white/10">
                    <summary class="cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Technical details') }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __('Source location in application code') }}
                                </p>
                            </div>
                            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open/tech:rotate-180" />
                        </div>
                    </summary>
                    <div class="border-t border-gray-100 px-3 py-3 dark:border-white/10">
                        <p class="ff-ltr-data font-mono text-xs text-gray-700 dark:text-gray-200" dir="ltr">
                            {{ $panel['location'] }}
                        </p>
                    </div>
                </details>
            @endif

            @if ($errors !== [])
                <details
                    class="group/errors rounded-lg border border-gray-100 dark:border-white/10"
                    @if ($errorsOpen) open @endif
                >
                    <summary class="cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ __('Row issues') }}
                                </p>
                                <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __('Row errors (:shown of :total)', [
                                        'shown' => count($errors),
                                        'total' => (int) ($panel['errors_total'] ?? count($errors)),
                                    ]) }}
                                </p>
                            </div>
                            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-gray-400 transition group-open/errors:rotate-180" />
                        </div>
                    </summary>
                    <div class="border-t border-gray-100 px-3 py-3 dark:border-white/10">
                        <ul class="ff-ltr-data max-h-72 list-disc space-y-1.5 overflow-y-auto pe-1 ps-5 text-xs leading-relaxed text-gray-800 dark:text-gray-100" dir="ltr">
                            @foreach ($errors as $error)
                                <li class="break-words">{{ $error }}</li>
                            @endforeach
                        </ul>
                        @if ($panel['errors_truncated'] ?? false)
                            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                {{ __('Showing the first :count errors. Fix these, re-upload if needed, then import again.', [
                                    'count' => count($errors),
                                ]) }}
                            </p>
                        @endif
                    </div>
                </details>
            @endif
        </div>
    </details>
@endif
