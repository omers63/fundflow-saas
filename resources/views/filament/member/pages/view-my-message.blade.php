<x-filament-panels::page>
    <div class="mx-auto max-w-3xl space-y-4">
        @foreach ($this->getThreadMessages() as $message)
            @php
                $isMine = (int) $message->from_user_id === (int) auth('tenant')->id();
            @endphp
            <div @class([
                'rounded-xl border px-4 py-3 text-sm shadow-sm',
                'border-emerald-200/80 bg-emerald-50/40 dark:border-emerald-500/25 dark:bg-emerald-950/20 ms-8' => $isMine,
                'border-gray-200/80 bg-white dark:border-gray-700 dark:bg-gray-800 me-8' => !$isMine,
            ])>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                    <span class="font-semibold text-gray-700 dark:text-gray-200">
                        {{ $message->sender?->name ?? __('Unknown') }}
                    </span>
                    <span>{{ $message->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</span>
                </div>
                <div
                    class="prose prose-sm dark:prose-invert max-w-none whitespace-pre-wrap text-gray-800 dark:text-gray-100">
                    {{ $message->body }}
                </div>
                @if (filled($message->attachments))
                    <ul class="mt-2 space-y-1 text-xs">
                        @foreach ($message->attachments as $file)
                            <li>
                                <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($file) }}"
                                    class="text-emerald-600 hover:underline dark:text-emerald-400" target="_blank" rel="noopener">
                                    {{ basename($file) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>