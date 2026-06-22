@props([
    'value' => null,
    'currency' => null,
    'signed' => false,
    'precision' => 2,
    'compact' => false,
])
@php
    use App\Filament\Support\MoneyDisplay;

    $digits = MoneyDisplay::amount($value, (int) $precision);
    $colorClass = null;
    if ($value !== null && $value !== '') {
        if ((float) $value < 0) {
            $colorClass = 'ff-member-amount--danger';
        } elseif ($signed) {
            $colorClass = 'ff-member-amount--'.MoneyDisplay::color($value);
        }
    }
@endphp

@if (filled($digits))
        @if ($compact)
            {!! MoneyDisplay::compactHtml((float) $value, $currency)->toHtml() !!}
        @else
            <span
                {{ $attributes->class(array_filter(['ff-member-amount', 'tabular-nums', $colorClass])) }}
                dir="ltr"
            >
                {!! MoneyDisplay::symbolHtml($currency)->toHtml() !!}
                <span class="ff-member-amount__digits">{{ $digits }}</span>
            </span>
        @endif
@endif
