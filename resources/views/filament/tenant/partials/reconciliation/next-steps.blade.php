@php($steps = $this->getNextSteps())

@if ($steps !== [])
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/60">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('What to do next') }}</h3>
        <ol class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($steps as $step)
                <li class="flex flex-wrap items-start gap-2">
                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-sky-500"></span>
                    <span class="min-w-0 flex-1">{{ $step['label'] }}</span>
                    @if (filled($step['url'] ?? null))
                        <a href="{{ $step['url'] }}"
                            class="shrink-0 text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('Go') }}</a>
                    @elseif (filled($step['tab'] ?? null))
                        <button type="button" wire:click="setSideTab('{{ $step['tab'] }}')"
                            class="shrink-0 text-xs font-semibold text-sky-600 hover:underline dark:text-sky-400">{{ __('Review') }}</button>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif