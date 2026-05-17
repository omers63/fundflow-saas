<div class="member-login-shell">
    <div class="member-login-card member-login-card--admin">
        <div class="member-login-card__header">
            <div class="member-login-card__icon" aria-hidden="true">
                <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M2.25 21h19.5M3.75 9.75V6a2.25 2.25 0 012.25-2.25h12A2.25 2.25 0 0120.25 6v3.75M3.75 9.75h16.5M6 12.75h.008v.008H6v-.008zm2.25 0h.008v.008H8.25v-.008zm2.25 0h.008v.008h-.008v-.008zm2.25 0h.008v.008h-.008v-.008zm2.25 0h.008v.008H15v-.008zm2.25 0h.008v.008h-.008v-.008zm2.25 0h.008v.008H19.5v-.008z" />
                </svg>
            </div>
            <h2 class="member-login-card__title">{{ __('Welcome back') }}</h2>
            <p class="member-login-card__subtitle">{{ __('Sign in to the fund administration dashboard') }}</p>
        </div>

        <div class="member-login-card__body">
            <form wire:submit="login" class="member-login-form">
                <div>
                    <label class="member-login-label" for="admin-login-email">{{ __('Email address') }}</label>
                    <input id="admin-login-email" wire:model="email" type="email" autocomplete="email"
                        placeholder="{{ __('your@email.com') }}"
                        class="member-login-input @error('email') member-login-input--error @enderror">
                    @error('email')
                        <p class="member-login-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="member-login-label" for="admin-login-password">{{ __('Password') }}</label>
                    <input id="admin-login-password" wire:model="password" type="password"
                        autocomplete="current-password" placeholder="{{ __('••••••••') }}"
                        class="member-login-input @error('password') member-login-input--error @enderror">
                    @error('password')
                        <p class="member-login-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="member-login-form__options">
                    <label class="member-login-remember">
                        <input wire:model="remember" type="checkbox" class="member-login-remember__checkbox">
                        <span>{{ __('Remember me') }}</span>
                    </label>
                </div>

                <button type="submit" wire:loading.attr="disabled" class="member-login-submit">
                    <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
                    <span wire:loading wire:target="login" class="member-login-submit__loading">
                        <svg class="member-login-submit__spinner" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ __('Signing in...') }}
                    </span>
                </button>
            </form>

            <div class="member-login-links">
                <p>
                    {{ __('Signing in as a member?') }}
                    <a href="{{ \Filament\Facades\Filament::getPanel('member')->getLoginUrl() }}"
                        class="member-login-links__accent">{{ __('Member portal') }}</a>
                </p>
            </div>
        </div>
    </div>
</div>