@php
    use App\Filament\Support\MoneyDisplay;

    $typeLabel = match ($applicationType) {
        'new' => __('New membership'),
        'resume' => __('Resume membership'),
        'renew' => __('Renew membership'),
        default => ucfirst($applicationType),
    };
    $feeFormatted = MoneyDisplay::format($currentFee, $currency) ?? '';
@endphp

<h2 class="mb-6 text-xl font-bold text-gray-900">
    {{ __('Step :number: :title', ['number' => $step, 'title' => __('Membership fees')]) }}
</h2>

<div class="enrollment-fee-hero">
    <div class="enrollment-fee-hero__header">
        <div>
            <p class="enrollment-fee-hero__eyebrow">{{ __('Your selection') }}</p>
            <p class="enrollment-fee-hero__title">{{ $typeLabel }}</p>
        </div>
        <div class="enrollment-fee-hero__amount-chip">
            <p class="enrollment-fee-hero__amount-label">{{ __('Amount to transfer') }}</p>
            <p class="enrollment-fee-hero__amount-value">{{ $feeFormatted }}</p>
        </div>
    </div>

    <ul class="enrollment-fee-hero__instructions">
        <li>
            <svg class="enrollment-fee-hero__icon enrollment-fee-hero__icon--success" fill="currentColor"
                viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                    clip-rule="evenodd" />
            </svg>
            <span>
                {{ __('Transfer') }}
                <strong class="font-semibold">{{ $feeFormatted }}</strong>
                {{ __('to the fund bank account using the details below.') }}
            </span>
        </li>
        <li>
            <svg class="enrollment-fee-hero__icon enrollment-fee-hero__icon--warning" fill="currentColor"
                viewBox="0 0 20 20" aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd" />
            </svg>
            <span>
                {{ __('Your application can be') }}
                <strong class="font-semibold">{{ __('approved only after') }}</strong>
                {{ __('we can match this payment to your transfer reference.') }}
            </span>
        </li>
        <li>
            <svg class="enrollment-fee-hero__icon enrollment-fee-hero__icon--info" fill="currentColor"
                viewBox="0 0 20 20" aria-hidden="true">
                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
            </svg>
            <span>
                {{ __('Fees are recorded against the fund’s') }}
                <strong class="font-semibold">{{ __('cash account') }}</strong>
                {{ __('(not the pooled master fund).') }}
            </span>
        </li>
    </ul>
</div>

@if ($feeTransferBankName && $feeTransferIban)
    <div class="enrollment-callout-emerald">
        <p class="mb-2">
            <span class="enrollment-callout-emerald__label">{{ __('Bank transfer details') }}</span>
        </p>
        <p class="mb-3 text-gray-600">
            {{ __('Use exactly these details when sending :amount', ['amount' => $feeFormatted]) }}
        </p>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="font-medium text-gray-600">{{ __('Bank name') }}</dt>
                <dd class="mt-0.5 font-semibold text-gray-900">{{ $feeTransferBankName }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-600">{{ __('IBAN') }}</dt>
                <dd class="mt-0.5 break-all font-mono text-sm font-semibold tracking-wide text-gray-900" dir="ltr">
                    {{ $feeTransferIban }}
                </dd>
            </div>
        </dl>
    </div>
@else
    <p class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ __('Bank transfer details have not been configured yet—please contact the fund administrators.') }}
    </p>
@endif

<div class="enrollment-panel-gray">
    <p class="font-medium text-gray-900">{{ __('Application summary') }}</p>
    <ul class="mt-2 space-y-1">
        <li>{{ __('Type') }}: {{ $typeLabel }}</li>
        <li>{{ __('Name') }}: {{ $name }}</li>
        <li>{{ __('Email') }}: {{ $email }}</li>
        <li class="font-semibold text-gray-900">
            {{ __('Fee') }}: {{ $feeFormatted }}
        </li>
        @if (filled($membership_fee_transfer_date))
            <li>{{ __('Transfer Date') }}: {{ $membership_fee_transfer_date }}</li>
        @endif
        @if (filled($membership_fee_transfer_amount))
            <li>{{ __('Transfer Amount') }}:
                {!! MoneyDisplay::html((float) $membership_fee_transfer_amount, $currency)?->toHtml() !!}
            </li>
        @endif
    </ul>
</div>

<div class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="membership_fee_transfer_date" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Transfer Date') }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="membership_fee_transfer_date" type="date" id="membership_fee_transfer_date"
                max="{{ now()->toDateString() }}"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            @error('membership_fee_transfer_date') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="membership_fee_transfer_amount" class="mb-1.5 block text-sm font-medium text-gray-700">
                {{ __('Transfer Amount') }} <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <span
                    class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-4 text-sm text-gray-500"
                    dir="ltr">@include('filament.partials.currency-symbol', ['currency' => $currency])</span>
                <input wire:model="membership_fee_transfer_amount" type="number" id="membership_fee_transfer_amount"
                    min="0.01" step="0.01" inputmode="decimal"
                    class="w-full rounded-xl border border-gray-200 bg-gray-50 py-3 ps-14 pe-4 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
            </div>
            @error('membership_fee_transfer_amount') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-gray-500">
                {{ __('Expected fee for :type: :amount', ['type' => $typeLabel, 'amount' => $feeFormatted]) }}
            </p>
        </div>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Transfer receipt') }}
            <span class="text-xs font-normal text-gray-500">({{ __('optional') }})</span>
        </label>
        <label
            class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 px-4 py-6 transition hover:border-emerald-400 hover:bg-emerald-50/30">
            <input wire:model="membership_fee_receipt" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png">
            @if ($membership_fee_receipt)
                <p class="text-sm font-medium text-gray-900">{{ $membership_fee_receipt->getClientOriginalName() }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('Tap to replace') }}</p>
            @else
                <p class="text-sm text-gray-600">{{ __('Upload a photo or PDF of your bank transfer receipt') }}</p>
            @endif
        </label>
        @error('membership_fee_receipt') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="membership_fee_transfer_reference" class="mb-1.5 block text-sm font-medium text-gray-700">
            {{ __('Your transfer reference / note') }} <span class="text-red-500">*</span>
        </label>
        <input wire:model="membership_fee_transfer_reference" type="text" id="membership_fee_transfer_reference"
            placeholder="{{ __('As shown on your bank receipt') }}"
            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 font-mono focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20">
        @error('membership_fee_transfer_reference') <p class="mt-1 text-sm text-red-500">{{ $message }}</p> @enderror
        <p class="mt-1 text-xs text-gray-500">
            {{ __('Enter the same reference you used on the transfer so we can approve your application once the payment is verified.') }}
        </p>
    </div>

    <label
        class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 transition hover:bg-gray-50">
        <input wire:model="membership_fee_acknowledged" type="checkbox"
            class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
        <span class="text-sm leading-relaxed text-gray-700">
            {{ __('I confirm that I have transferred :amount to the fund bank account above, and I understand that my application will be reviewed after this payment can be matched.', [
    'amount' => $feeFormatted,
]) }}
        </span>
    </label>
    @error('membership_fee_acknowledged') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
</div>

@error('enrollment')
    <p class="mt-4 text-sm text-red-500">{{ $message }}</p>
@enderror