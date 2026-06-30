@props([
    'breakdown',
    'currency',
    'compact' => true,
])

@php
    $hasSplit = (bool) ($breakdown['has_split'] ?? false);
@endphp

@if ($hasSplit)
    <div
        {{ $attributes->class([
            'ff-loan-outstanding-breakdown flex flex-wrap items-center gap-1.5',
        ]) }}
        role="list"
        aria-label="{{ __('Outstanding breakdown') }}"
    >
        <span
            role="listitem"
            class="ff-loan-outstanding-breakdown__chip ff-loan-outstanding-breakdown__chip--scheduled"
        >
            <span class="ff-loan-outstanding-breakdown__label">{{ __('Scheduled') }}</span>
            <x-member::amount
                :value="$breakdown['scheduled']"
                :currency="$currency"
                :compact="$compact"
                class="ff-loan-outstanding-breakdown__value"
            />
        </span>
        <span
            role="listitem"
            class="ff-loan-outstanding-breakdown__chip ff-loan-outstanding-breakdown__chip--partial"
        >
            <span class="ff-loan-outstanding-breakdown__label">{{ __('Partial paid') }}</span>
            <x-member::amount
                :value="$breakdown['partial_paid']"
                :currency="$currency"
                :compact="$compact"
                class="ff-loan-outstanding-breakdown__value"
            />
        </span>
    </div>
@endif
