@props(['household', 'profileUrl' => null, 'dependentsUrl' => null])

@if (count($household['dependents'] ?? []) > 0 || filled($household['parent_name'] ?? null))
    <div
        class="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700">
            <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                {{ __('Household') }}
            </h4>
        </div>
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @if (filled($household['parent_name'] ?? null))
                <div class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 dark:text-gray-300">
                    <x-heroicon-o-user class="h-4 w-4 text-sky-500" />
                    <span>{{ __('Parent') }}: <strong
                            class="text-gray-900 dark:text-white">{{ $household['parent_name'] }}</strong></span>
                </div>
            @endif
            @foreach ($household['dependents'] as $dependent)
                <div class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                    <span class="font-medium text-gray-900 dark:text-white">{{ $dependent['name'] }}</span>
                    <span class="text-[10px] text-gray-400">{{ $dependent['number'] }} · {{ $dependent['status'] }}</span>
                </div>
            @endforeach
            @if (filled($dependentsUrl ?? null) || filled($profileUrl))
                <div class="flex flex-wrap gap-3 border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                    @if (filled($dependentsUrl ?? null))
                        <a href="{{ $dependentsUrl }}"
                            class="text-[10px] font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('Manage dependents') }} →
                        </a>
                    @endif
                    @if (filled($profileUrl))
                        <a href="{{ $profileUrl }}"
                            class="text-[10px] font-medium text-gray-500 hover:underline dark:text-gray-400">
                            {{ __('Household profiles') }} →
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif