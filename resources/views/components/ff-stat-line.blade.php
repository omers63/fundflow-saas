@props([
    'text' => null,
    'amount' => null,
    'currency' => null,
    'precision' => 2,
])
@php
    use App\Filament\Support\MoneyDisplay;

    $title = filled($amount)
        ? (MoneyDisplay::format($amount, $currency, precision: (int) $precision) ?? '—')
        : ($text ?? '');
@endphp
<p {{ $attributes->merge(['title' => e(strip_tags((string) $title))]) }}>
@if (filled($amount))
    <x-member::amount :value="$amount" :currency="$currency" :precision="$precision" />
@elseif ($slot->isNotEmpty())
        {!! MoneyDisplay::markupForDisplay($slot->toHtml(), $currency, precision: (int) $precision) !!}
    @elseif (filled($text))
        {!! MoneyDisplay::markupForDisplay($text, $currency, precision: (int) $precision) !!}
    @endif
</p>
