@php
    $resolvedIconColor = is_array($iconColor) ? ($iconColor[0] ?? 'primary') : ($iconColor ?? 'primary');
@endphp

<div class="ff-confirm-modal">
    @if (filled($icon))
        <div @class(['ff-confirm-modal__icon', "ff-confirm-modal__icon--{$resolvedIconColor}"])>
            <span class="ff-confirm-modal__icon-ring" aria-hidden="true">
                {{ \Filament\Support\generate_icon_html($icon, size: \Filament\Support\Enums\IconSize::Medium) }}
            </span>
        </div>
    @endif

    <div class="ff-confirm-modal__copy">
        <h2 class="ff-confirm-modal__title">{{ $heading }}</h2>

        @if (filled($description))
            <p class="ff-confirm-modal__description">{{ $description }}</p>
        @endif
    </div>
</div>