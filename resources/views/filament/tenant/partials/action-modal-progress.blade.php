<div class="ff-action-modal-progress" wire:loading.delay.shortest.flex wire:target="callMountedAction" role="status"
    aria-live="polite" aria-busy="true">
    <div class="ff-action-modal-progress__head">
        <span class="ff-action-modal-progress__spinner" aria-hidden="true"></span>
        <p class="ff-action-modal-progress__label">{{ __('Working…') }}</p>
    </div>

    <div class="ff-action-modal-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <span class="ff-action-modal-progress__bar"></span>
    </div>

    @if (filled($message))
        <p class="ff-action-modal-progress__hint">{{ $message }}</p>
    @endif
</div>