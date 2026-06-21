@props([
    'text' => null,
    'currency' => null,
    'precision' => 2,
])
@php
    use App\Filament\Support\MoneyDisplay;
@endphp
@if (filled($text))
    <span {{ $attributes->class('ff-money-text') }}>
        {!! MoneyDisplay::markupForDisplay($text, $currency, precision: (int) $precision) !!}
    </span>
@endif
