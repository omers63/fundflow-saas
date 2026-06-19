@props([
    'items' => [],
])

@if (! empty($items))
    <div class="ff-member-faq space-y-2">
        @foreach ($items as $index => $item)
            <details class="ff-member-faq__item rounded-xl border border-gray-200 bg-white" @if ($index === 0) open @endif>
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-900">
                    {{ $item['question'] }}
                </summary>
                <div class="border-t border-gray-100 px-4 py-3 text-sm text-gray-600">
                    {{ $item['answer'] }}
                </div>
            </details>
        @endforeach
    </div>
@else
    <p class="text-sm text-gray-500">{{ __('No FAQ entries are available yet.') }}</p>
@endif
