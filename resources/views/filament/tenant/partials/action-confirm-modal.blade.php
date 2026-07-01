@php
$resolvedIconColor = is_array($iconColor) ? ($iconColor[0] ?? 'primary') : ($iconColor ?? 'primary');
$resolvedTone = $tone ?? 'primary';
@endphp
    
    <div @class(['ff-confirm-modal', "ff-confirm-modal--{$resolvedTone}"])>
        <div @class(['ff-confirm-modal__hero', "ff-confirm-modal__hero--{$resolvedTone}"]) aria-hidden="true">
        <div @class(['ff-confirm-modal__icon', "ff-confirm-modal__icon--{$resolvedIconColor}"])>
            <span class="ff-confirm-modal__icon-ring">
                {{ \Filament\Support\generate_icon_html($icon, size: \Filament\Support\Enums\IconSize::Large) }}
            </span>
            </div>
            </div>
            
            <div class="ff-confirm-modal__body">
                <h2 class="ff-confirm-modal__title">{{ $heading }}</h2>
            
                @if (filled($description))
                    <p class="ff-confirm-modal__description">{{ $description }}</p>
                @endif
            </div>
            </div>