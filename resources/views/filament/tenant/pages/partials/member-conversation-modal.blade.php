<div class="space-y-3">
    <div
        class="max-h-[22rem] overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/20 p-3">
        @forelse($messages as $msg)
            @php $isMine = (int) $msg->from_user_id === (int) $userId; @endphp
            <div class="mb-3 flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[85%]">
                    <p class="mb-1 text-[11px] text-gray-500 {{ $isMine ? 'text-right' : '' }}">
                        {{ $msg->sender?->name ?? __('Unknown') }} ·
                        {{ $msg->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}
                    </p>
                    <div @class([
                        'rounded-xl px-3 py-2 text-sm whitespace-pre-wrap',
                        'bg-primary-600 text-white rounded-tr-none' => $isMine,
                        'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-tl-none border border-gray-200 dark:border-gray-700' => !$isMine,
                    ])>
                        {{ $msg->body }}
                    </div>
                    @if (is_array($msg->attachments) && count($msg->attachments) > 0)
                        <div class="mt-2 space-y-1">
                            @foreach ($msg->attachments as $attachment)
                                <a href="{{ route('tenant.direct-messages.attachment', ['message' => $msg->id, 'index' => $loop->index]) }}"
                                    target="_blank" rel="noopener" @class([
                                        'inline-flex items-center gap-1 text-xs underline',
                                        'text-primary-100' => $isMine,
                                        'text-primary-600 dark:text-primary-400' => !$isMine,
                                    ])>
                                    <x-heroicon-o-paper-clip class="w-3 h-3" />
                                    {{ basename($attachment) }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="py-8 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No messages in this conversation yet.') }}</p>
            </div>
        @endforelse
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        {{ __('Type your new message below and click "Send Message".') }}
    </p>
</div>