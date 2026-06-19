@props([
    'label',
    'value' => null,
    'hint' => null,
    'amount' => null,
    'currency' => null,
    'precision' => 2,
])

@php
    use App\Filament\Support\MoneyDisplay;
@endphp

<div {{ $attributes->class(['ff-member-stat-card']) }}>
    <p class="ff-member-stat-card__label">{{ $label }}</p>
    <p class="ff-member-stat-card__value">
        @if (filled($amount))
            <x-member::amount :value="$amount" :currency="$currency" :precision="$precision" />
        @elseif (filled($value))
            {!! MoneyDisplay::markupForDisplay($value, $currency, precision: (int) $precision) !!}
        @else
            —
        @endif
    </p>
    @if (filled($hint))
        <p class="ff-member-stat-card__hint">{!! MoneyDisplay::markupForDisplay($hint, $currency, precision: (int) $precision) !!}</p>
    @endif
</div>
