<div>
    @if ($submitted)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-8 text-center">
            <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ __('Application submitted') }}</h3>
            <p class="text-gray-600">
                {{ __('Thank you. Fund administrators will review your application and contact you by email.') }}
            </p>
        </div>
    @else
        <form wire:submit="submit" class="bg-white border border-gray-200 rounded-2xl p-8 shadow-sm space-y-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                    {{ __('Full name') }} <span class="text-red-500">*</span>
                </label>
                <input wire:model="name" type="text" id="name"
                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors"
                    placeholder="{{ __('Enter your full name') }}">
                @error('name') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                    {{ __('Email address') }} <span class="text-red-500">*</span>
                </label>
                <input wire:model="email" type="email" id="email"
                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors"
                    placeholder="{{ __('you@example.com') }}">
                @error('email') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('Phone number') }}</label>
                <input wire:model="phone" type="tel" id="phone"
                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors"
                    placeholder="{{ __('Contact numbers') }}">
                @error('phone') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="message" class="block text-sm font-medium text-gray-700 mb-1.5">
                    {{ __('Additional message (optional)') }}
                </label>
                <textarea wire:model="message" id="message" rows="4"
                    class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-colors resize-none"
                    placeholder="{{ __('Any notes for the fund administrators…') }}"></textarea>
                @error('message') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="w-full py-3.5 bg-emerald-600 text-white font-semibold rounded-xl hover:bg-emerald-700 transition-colors shadow-lg shadow-emerald-600/25 flex items-center justify-center gap-2"
                wire:loading.attr="disabled" wire:loading.class="opacity-75 cursor-wait">
                <span wire:loading.remove>{{ __('Submit application') }}</span>
                <span wire:loading>
                    <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    {{ __('Submitting…') }}
                </span>
            </button>

            <p class="text-center text-sm text-gray-500">
                {{ __('Not a member yet?') }}
                <a href="{{ route('filament.member.auth.login') }}"
                    class="text-emerald-600 font-medium hover:text-emerald-700">{{ __('Member login') }}</a>
            </p>
        </form>
    @endif
</div>
