@props([
    'id',
    'autocomplete' => 'current-password',
    'placeholder' => null,
    'variant' => 'login',
    'error' => false,
])

@php
    $inputClass = match ($variant) {
        'enrollment' => 'w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 pe-11 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20',
        default => 'member-login-input member-login-input--with-toggle'.($error ? ' member-login-input--error' : ''),
    };
@endphp

<div
    x-data="{ show: false }"
    @class([
        'member-login-password-wrap' => $variant === 'login',
        'relative' => $variant === 'enrollment',
    ])
>
    <input
        {{ $attributes->class([$inputClass])->merge([
            'id' => $id,
            'autocomplete' => $autocomplete,
            'placeholder' => $placeholder,
        ]) }}
        x-bind:type="show ? 'text' : 'password'"
    >

    <button
        type="button"
        @class([
            'member-login-password-toggle' => $variant === 'login',
            'absolute inset-e-3 top-1/2 -translate-y-1/2 rounded-md p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40' => $variant === 'enrollment',
        ])
        aria-label="{{ __('Show password') }}"
        title="{{ __('Show password') }}"
        x-on:click="show = ! show"
        x-bind:aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
        x-bind:title="show ? @js(__('Hide password')) : @js(__('Show password'))"
    >
        <svg x-show="! show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        <svg x-cloak x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
