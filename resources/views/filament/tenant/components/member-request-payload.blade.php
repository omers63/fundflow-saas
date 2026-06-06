@php
    /** @var \App\Models\Tenant\MemberRequest $record */
@endphp
<div class="space-y-3 text-sm">
    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Type') }}</dt>
            <dd class="font-medium">{{ \App\Models\Tenant\MemberRequest::typeLabel($record->type) }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</dt>
            <dd class="font-medium">{{ \App\Models\Tenant\MemberRequest::statusOptions()[$record->status] ?? $record->status }}</dd>
        </div>
    </dl>
    <div>
        <p class="text-gray-500 dark:text-gray-400 mb-1">{{ __('Summary') }}</p>
        <p>{{ $record->describePayload() }}</p>
    </div>
    @if($record->admin_note)
        <div>
            <p class="text-gray-500 dark:text-gray-400 mb-1">{{ __('Admin note (if any)') }}</p>
            <p>{{ $record->admin_note }}</p>
        </div>
    @endif
    <div>
        <p class="text-gray-500 dark:text-gray-400 mb-1">{{ __('Payload') }}</p>
        <textarea readonly rows="14" class="block w-full max-h-96 min-h-[8rem] resize-y overflow-auto rounded-lg border border-gray-200 bg-white p-3 font-mono text-xs leading-relaxed text-gray-900 dark:border-white/10 dark:bg-gray-950 dark:text-gray-100">{{ $record->payloadAsPlainText() }}</textarea>
    </div>
</div>
