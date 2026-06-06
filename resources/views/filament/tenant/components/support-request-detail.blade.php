@php
    /** @var \App\Models\Tenant\SupportRequest $record */
    $categoryLabel = \App\Models\Tenant\SupportRequest::categoryLabel($record->category);
    $name = $record->user?->name ?? '—';
    $memberLine = $record->member
        ? $name.' ('.$record->member->member_number.')'
        : $name;
@endphp
<div class="space-y-3 text-sm">
    <div>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('From') }}</span>
        <p class="mt-0.5 text-gray-900 dark:text-white">{{ $memberLine }}</p>
    </div>
    <div>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Category') }}</span>
        <p class="mt-0.5 text-gray-900 dark:text-white">{{ $categoryLabel }}</p>
    </div>
    <div>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Subject') }}</span>
        <p class="mt-0.5 text-gray-900 dark:text-white">{{ $record->subject }}</p>
    </div>
    <div>
        <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Message') }}</span>
        <p class="mt-1 whitespace-pre-wrap text-gray-900 dark:text-white">{{ $record->message }}</p>
    </div>
    <div class="text-xs text-gray-500 dark:text-gray-400">
        {{ __('Submitted') }} {{ $record->created_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}
    </div>
</div>
