<h2 class="mb-6 text-xl font-bold text-gray-900">
    {{ __('Step :number: :title', ['number' => $step, 'title' => __('Employment & next of kin')]) }}
</h2>

<div class="space-y-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Employment (optional)') }}</p>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="occupation"
                class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Occupation') }}</label>
            <input wire:model="occupation" type="text" id="occupation"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('occupation') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="employer" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Employer') }}</label>
            <input wire:model="employer" type="text" id="employer"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('employer') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="monthly_income" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Monthly income') }} ({{ $currency }})
        </label>
        <input wire:model="monthly_income" type="number" step="0.01" id="monthly_income"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('monthly_income') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div class="border-t border-gray-100 pt-5">
        <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400">
            {{ __('Next of kin (optional)') }}
        </p>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="next_of_kin_name" class="mb-1.5 block text-sm font-medium text-gray-700">
                    {{ __('Full name') }}
                </label>
                <input wire:model="next_of_kin_name" type="text" id="next_of_kin_name"
                    class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                @error('next_of_kin_name') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="next_of_kin_phone" class="mb-1.5 block text-sm font-medium text-gray-700">
                    {{ __('Phone number') }}
                </label>
                <input wire:model="next_of_kin_phone" type="tel" id="next_of_kin_phone"
                    class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                @error('next_of_kin_phone') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>
</div>