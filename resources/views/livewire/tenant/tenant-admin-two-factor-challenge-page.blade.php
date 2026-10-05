<div class="member-login-shell">
    <div class="member-login-card member-login-card--admin">
        <div class="member-login-card__header">
            <h2 class="member-login-card__title">{{ __('Two-factor authentication') }}</h2>
            <p class="member-login-card__subtitle">{{ __('Enter the 6-digit code from your authenticator app, or a recovery code.') }}</p>
        </div>

        <div class="member-login-card__body">
            <form wire:submit="verify" class="member-login-form">
                <div>
                    <label class="member-login-label" for="admin-2fa-code">{{ __('Authentication code') }}</label>
                    <input id="admin-2fa-code" wire:model="code" type="text" autocomplete="one-time-code"
                        inputmode="numeric"
                        placeholder="123456"
                        class="member-login-input @error('code') member-login-input--error @enderror">
                    @error('code')
                        <p class="member-login-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="member-login-submit">
                    {{ __('Verify') }}
                </button>
            </form>
        </div>
    </div>
</div>
