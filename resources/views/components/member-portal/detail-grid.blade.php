@props([
    'items' => [],
])

<div {{ $attributes->class(['ff-member-detail-grid']) }}>
    @foreach ($items as $item)
        <div class="ff-member-detail-grid__item">
            <p class="ff-member-detail-grid__label">{{ $item['label'] ?? '' }}</p>
            <p class="ff-member-detail-grid__value">{!! \App\Filament\Support\MoneyDisplay::markupForDisplay($item['value'] ?? '') !!}</p>
        </div>
    @endforeach
</div>
