<div class="member-login-shell">
    <div class="member-login-card">
        <div class="member-login-card__header">
            <div class="member-login-card__icon" aria-hidden="true">
                <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h2 class="member-login-card__title">{{ __('Welcome back') }}</h2>
            <p class="member-login-card__subtitle">{{ __('Sign in to your member portal account') }}</p>
        </div>

        <div class="member-login-card__body">
            @if ($statusType === 'suspended')
                <div class="member-login-alert member-login-alert--rose">
                    <p class="member-login-alert__title">{{ __('Membership suspended') }}</p>
                    <p class="member-login-alert__text">{{ $statusMessage }}</p>
                </div>
            @endif

            @if ($statusType === 'withdrawn')
                <div class="member-login-alert member-login-alert--slate">
                    <p class="member-login-alert__title">{{ __('Membership withdrawn') }}</p>
                    <p class="member-login-alert__text">{{ $statusMessage }}</p>
                </div>
            @endif

            @if ($statusType === 'delinquent')
                <div class="member-login-alert member-login-alert--rose">
                    <p class="member-login-alert__title">{{ __('Membership delinquent') }}</p>
                    <p class="member-login-alert__text">{{ $statusMessage }}</p>
                </div>
            @endif

            @if ($statusType === 'terminated')
                <div class="member-login-alert member-login-alert--slate">
                    <p class="member-login-alert__title">{{ __('Membership terminated') }}</p>
                    <p class="member-login-alert__text">{{ $statusMessage }}</p>
                </div>
            @endif

            @if ($statusType === 'maintenance')
                <div class="member-login-alert member-login-alert--amber">
                    <p class="member-login-alert__title">{{ __('System under maintenance') }}</p>
                    <p class="member-login-alert__text">{{ $statusMessage }}</p>
                </div>
            @endif
            
            @if ($statusType !== 'maintenance' && !$showProfilePicker)
                <form wire:submit="login" class="member-login-form">
                    <div>
                        <label class="member-login-label" for="member-login-email">{{ __('Email address') }}</label>
                        <input id="member-login-email" wire:model="email" type="email" autocomplete="email"
                            placeholder="{{ __('your@email.com') }}"
                            class="member-login-input @error('email') member-login-input--error @enderror">
                        @error('email')
                            <p class="member-login-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="member-login-label" for="member-login-password">{{ __('Password') }}</label>
                        <input id="member-login-password" wire:model="password" type="password"
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
            @elseif ($statusType !== 'maintenance' && $showProfilePicker)
                <form wire:submit="verifySelectedProfile" class="member-login-form member-login-profile-picker">
                    <div>
                        <p class="member-login-profile-picker__heading">{{ __('Who is accessing the portal?') }}</p>
                        <p class="member-login-profile-picker__hint">
                            {{ __('Select a profile, then verify using PIN/password.') }}
                        </p>
                    </div>

                    <div class="member-login-profile-grid" role="listbox" aria-label="{{ __('Select profile') }}">
                        @foreach ($availableProfiles as $profile)
                            <button type="button" wire:click="selectProfile({{ $profile['id'] }})"
                                class="member-login-profile-card @if ($selectedMemberId === $profile['id']) member-login-profile-card--selected @endif"
                                role="option" aria-selected="{{ $selectedMemberId === $profile['id'] ? 'true' : 'false' }}">
                                <span class="member-login-profile-card__avatar" aria-hidden="true">
                                    @if ($profile['avatar_url'])
                                        <img src="{{ $profile['avatar_url'] }}" alt="">
                                    @else
                                        {{ strtoupper(mb_substr($profile['name'], 0, 1)) }}
                                    @endif
                                </span>
                                <span class="member-login-profile-card__name">
                                    <x-arabic-text :text="$profile['name']" />
                                </span>
                                <span class="member-login-profile-card__role">
                                    {{ $profile['is_parent'] ? __('Parent profile') : __('Dependent profile') }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                    @error('selectedMemberId')
                        <p class="member-login-error">{{ $message }}</p>
                    @enderror

                    <div>
                        <label class="member-login-label" for="member-login-verification">
                            {{ __('Verification code/password') }}
                        </label>
                        <input id="member-login-verification" wire:model="verificationSecret" type="password"
                            autocomplete="off" placeholder="{{ __('Enter parent PIN or dependent password') }}"
                            class="member-login-input @error('verificationSecret') member-login-input--error @enderror">
                        @error('verificationSecret')
                            <p class="member-login-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="member-login-profile-actions">
                        <button type="button" wire:click="backToEmailStep" class="member-login-profile-back">
                            {{ __('Back') }}
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                            class="member-login-submit member-login-submit--compact">
                            {{ __('Continue') }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($statusType !== 'maintenance')
            <div class="member-login-links">
                <p>
                    {{ __('Not a member yet?') }}
                    <a href="{{ route('tenant.membership') }}"
                        class="member-login-links__accent">{{ __('Apply for membership') }}</a>
                </p>
                <p>
                    {{ __('Applied already?') }}
                    <a href="{{ route('tenant.application.status') }}"
                        class="member-login-links__accent-teal">{{ __('Check your status') }}</a>
                </p>
            </div>
            @endif
        </div>
    </div>
</div>