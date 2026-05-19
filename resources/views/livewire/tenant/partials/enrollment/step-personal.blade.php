<h2 class="mb-6 text-xl font-bold text-gray-900">
    {{ __('Step :number: :title', ['number' => $step, 'title' => __('Personal information')]) }}
</h2>

<div class="mb-8">
    <p class="mb-1 text-sm font-medium text-gray-900">
        {{ __('Application type') }} <span class="text-red-500">*</span>
    </p>
    <p class="mb-4 text-sm text-gray-600">
        {{ __('Choose the option that matches your situation. Application fees (if any) depend on this choice—you will confirm payment in the final step before submitting.') }}
    </p>
    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($applicationTypes as $type => $option)
            @php $fee = $fees[$type]; @endphp
            <label @class([
                'relative flex cursor-pointer flex-col rounded-xl border p-4 transition-all',
                'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20' => $applicationType === $type,
                'border-gray-200 hover:border-gray-300 hover:bg-gray-50' => $applicationType !== $type,
            ])>
                <input type="radio" wire:model="applicationType" value="{{ $type }}" class="sr-only">
                @if ($fee <= 0)
                    <span
                        class="mb-2 inline-flex w-fit rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600">
                        {{ __('No fee') }}
                    </span>
                @else
                    <span
                        class="mb-2 inline-flex w-fit rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                        {{ $currency }} {{ number_format($fee, 2) }}
                    </span>
                @endif
                <span class="font-semibold text-gray-900">{{ $option['label'] }}</span>
                <span class="mt-1 text-sm leading-snug text-gray-600">{{ $option['description'] }}</span>
            </label>
        @endforeach
    </div>
    @error('applicationType')
        <p class="mt-2 text-sm text-red-500">{{ $message }}</p>
    @enderror
</div>

<div class="space-y-5">
    <div>
        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Full name') }} <span class="text-red-500">*</span>
        </label>
        <input wire:model="name" type="text" id="name"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('name') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Email address') }} <span class="text-red-500">*</span>
        </label>
        <input wire:model="email" type="email" id="email"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('email') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Password') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="password" type="password" id="password" autocomplete="new-password"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('password') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Confirm password') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="password_confirmation" type="password" id="password_confirmation"
                autocomplete="new-password"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        </div>
    </div>
</div>