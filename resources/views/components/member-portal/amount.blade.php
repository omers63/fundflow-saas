@props([
    'value' => null,
    'currency' => null,
    'signed' => false,
    'precision' => 2,
])
@php
    use App\Filament\Support\MoneyDisplay;

    $digits = MoneyDisplay::amount($value, (int) $precision);
    $colorClass = $signed && $value !== null && $value !== ''
        ? 'ff-member-amount--' . MoneyDisplay::color($value)
        : null;
@endphp

@if (filled($digits))
    <span
        {{ $attributes->class(array_filter(['ff-member-amount', 'tabular-nums', $colorClass])) }}
        dir="ltr"
    >
        <span class="{{ MoneyDisplay::symbolSpanClass($currency) }}" dir="ltr">{{ MoneyDisplay::symbol($currency) }}</span>
        <span class="ff-member-amount__digits">{{ $digits }}</span>
    </span>
@endif
