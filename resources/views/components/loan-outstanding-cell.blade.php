@props([
    'loan',
    'currency',
])

@php
    use App\Filament\Support\MoneyDisplay;

    $breakdown = $loan->getOutstandingBreakdown();
    $amountHtml = MoneyDisplay::html($loan->getOutstandingBalance(), $currency)?->toHtml() ?? '—';
@endphp

<div class="ff-loan-outstanding-cell">
    <div class="ff-loan-outstanding-cell__primary tabular-nums">
        {!! $amountHtml !!}
    </div>

    @if ($breakdown['has_split'])
        <x-loan-outstanding-breakdown
            :breakdown="$breakdown"
            :currency="$currency"
            class="ff-loan-outstanding-cell__meta"
        />
    @endif
</div>
