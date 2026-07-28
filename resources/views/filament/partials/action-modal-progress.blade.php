{{-- Always visible for long-running confirms (do not rely on wire:loading alone). --}}
@php
    $active = (bool) ($active ?? true);
@endphp

<div @class([
    'ff-action-modal-progress',
    'ff-action-modal-progress--active' => $active,
])
    wire:loading.class="ff-action-modal-progress--running" wire:target="callMountedAction" role="status"
    aria-live="polite">
    <div class="ff-action-modal-progress__head">
        <span class="ff-action-modal-progress__spinner" aria-hidden="true"></span>
        <p class="ff-action-modal-progress__label">
            <span wire:loading.remove.delay.shortest wire:target="callMountedAction">{{ __('Ready to run') }}</span>
            <span wire:loading.delay.shortest wire:target="callMountedAction">{{ __('Working…') }}</span>
        </p>
    </div>

    <div class="ff-action-modal-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <span class="ff-action-modal-progress__bar"></span>
    </div>

    @if (filled($message))
        <p class="ff-action-modal-progress__hint">{{ $message }}</p>
    @endif
</div>