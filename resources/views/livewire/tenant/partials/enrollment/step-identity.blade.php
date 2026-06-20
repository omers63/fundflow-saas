@php
    $typeLabel = match ($applicationType) {
        'new' => __('New membership'),
        'resume' => __('Resume membership'),
        'renew' => __('Renew membership'),
        default => ucfirst($applicationType),
    };
@endphp

<h2 class="mb-6 text-xl font-bold text-gray-900">
    {{ __('Step :number: :title', ['number' => $step, 'title' => __('Identity & address')]) }}
</h2>

<div class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3 text-sm text-gray-700">
    <span class="font-semibold text-emerald-900">{{ __('Application type:') }}</span>
    {{ $typeLabel }}
    @if ($requiresFeePayment)
        <span class="text-gray-600">
            — {{ __('application fee') }} <x-member::amount :value="$currentFee" :currency="$currency" class="inline" />
            ({{ __('you will confirm the bank transfer on the last step before submitting') }}).
        </span>
    @else
        <span class="text-gray-600"> — {{ __('no application fee for this type.') }}</span>
    @endif
</div>

<div class="space-y-5">
    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="gender" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Gender') }}</label>
            <select wire:model="gender" id="gender"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                <option value="">—</option>
                <option value="male">{{ __('Male') }}</option>
                <option value="female">{{ __('Female') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
            @error('gender') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="marital_status"
                class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Marital status') }}</label>
            <select wire:model="marital_status" id="marital_status"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
                <option value="">—</option>
                <option value="single">{{ __('Single') }}</option>
                <option value="married">{{ __('Married') }}</option>
                <option value="divorced">{{ __('Divorced') }}</option>
                <option value="widowed">{{ __('Widowed') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
            @error('marital_status') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="national_id" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('National ID') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="national_id" type="text" id="national_id"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('national_id') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="date_of_birth" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Date of birth') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="date_of_birth" type="date" id="date_of_birth"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('date_of_birth') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="address" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Full address') }} <span class="text-red-500">*</span>
        </label>
        <textarea wire:model="address" id="address" rows="3"
            class="w-full resize-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
            placeholder="{{ __('Street, building, district…') }}"></textarea>
        @error('address') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="city" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('City') }} <span class="text-red-500">*</span>
        </label>
        <input wire:model="city" type="text" id="city"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('city') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Contact numbers') }}</p>

    <div>
        <label for="mobile_phone" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Mobile phone') }} <span class="text-red-500">*</span>
        </label>
        <input wire:model="mobile_phone" type="tel" id="mobile_phone"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        <p class="mt-1 text-xs text-gray-500">
            {{ __('Used for contact and saved on your member profile after approval.') }}
        </p>
        @error('mobile_phone') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="home_phone"
                class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Home phone') }}</label>
            <input wire:model="home_phone" type="tel" id="home_phone"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('home_phone') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="work_phone"
                class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Work phone') }}</label>
            <input wire:model="work_phone" type="tel" id="work_phone"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('work_phone') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="work_place" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Work place') }}</label>
        <input wire:model="work_place" type="text" id="work_place"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('work_place') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="residency_place"
            class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Residency place') }}</label>
        <input wire:model="residency_place" type="text" id="residency_place"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('residency_place') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="bank_account_number" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Bank account number') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="bank_account_number" type="text" id="bank_account_number"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 font-mono focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('bank_account_number') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="iban" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('IBAN') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="iban" type="text" id="iban" dir="ltr"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 font-mono focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('iban') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="membership_date"
            class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('Membership date') }}</label>
        <input wire:model="membership_date" type="date" id="membership_date"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('membership_date') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>
</div>