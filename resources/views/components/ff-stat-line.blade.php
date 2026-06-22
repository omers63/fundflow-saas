@props([
    'text' => null,
    'amount' => null,
    'currency' => null,
    'precision' => 2,
    'compact' => false,
])
@php
    use App\Filament\Support\MoneyDisplay;

    $title = filled($amount)
        ? (MoneyDisplay::format($amount, $currency, precision: (int) $precision) ?? '—')
        : ($text ?? '');
@endphp
<p {{ $attributes->class('ff-stat-line')->merge(['title' => e(strip_tags((string) $title))]) }}>
    @if (filled($amount))
        @if ($compact)
            {!! MoneyDisplay::compactHtml((float) $amount, $currency)->toHtml() !!}
        @else
            <x-member::amount :value="$amount" :currency="$currency" :precision="$precision" />
        @endif
    @elseif ($slot->isNotEmpty())
        {!! MoneyDisplay::markupForDisplay($slot->toHtml(), $currency, precision: (int) $precision) !!}
    @elseif (filled($text))
        {!! MoneyDisplay::markupForDisplay($text, filled($amount) ? $currency : null, precision: (int) $precision) !!}
    @endif
</p>
