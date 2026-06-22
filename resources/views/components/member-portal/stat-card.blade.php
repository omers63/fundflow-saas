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

    $valueTitle = match (true) {
        filled($amount) => MoneyDisplay::format($amount, $currency, precision: (int) $precision),
        filled($value) => is_string($value) ? trim(strip_tags($value)) : null,
        default => null,
    };
@endphp
<div {{ $attributes->class(['ff-member-stat-card', 'min-w-0']) }}>
<p class="ff-member-stat-card__label" title="{{ $label }}">{{ $label }}</p>
    <p class="ff-member-stat-card__value" @if (filled($valueTitle)) title="{{ $valueTitle }}" @endif>
        @if (filled($amount))
            <x-member::amount :value="$amount" :currency="$currency" :precision="$precision" />
        @elseif (filled($value))
            {!! MoneyDisplay::markupForDisplay($value, filled($amount) ? $currency : null, precision: (int) $precision) !!}
        @else
            {{ __('—') }}
        @endif
    </p>
    @if (filled($hint))
        <p class="ff-member-stat-card__hint">{!! MoneyDisplay::markupForDisplay($hint, $currency, precision: (int) $precision) !!}</p>
    @endif
</div>
