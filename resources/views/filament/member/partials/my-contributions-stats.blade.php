@php
    use App\Support\Insights\InsightFormatter;

    /** @var list<array{label: string, value?: string, amount?: float, hint: ?string}> $cards */
    $cards = $cards ?? [];
    $currency = InsightFormatter::currency();
@endphp

@if ($cards === [])
    <div
        class="rounded-xl border border-dashed border-gray-200 px-4 py-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
        {{ __('Loading contribution summary…') }}
    </div>
@else
    <div class="ff-member-contributions-stats mb-1 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <x-member::stat-card :label="$card['label']" :value="$card['value'] ?? null" :amount="$card['amount'] ?? null"
                :currency="$currency" :hint="$card['hint'] ?? null" />
        @endforeach
    </div>
@endif
