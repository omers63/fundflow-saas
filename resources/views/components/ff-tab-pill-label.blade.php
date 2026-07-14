@props([
    'label',
    'key' => null,
])
@php
    use App\Filament\Support\UiLabelIcons;
@endphp
{!! UiLabelIcons::tabPillHtml($label, $key) !!}
