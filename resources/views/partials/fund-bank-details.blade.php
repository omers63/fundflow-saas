@php
    use App\Support\PublicPageSettings;

    $bankName = PublicPageSettings::feeTransferBankName();
    $iban = PublicPageSettings::feeTransferIban();
    $configured = PublicPageSettings::hasFeeTransferDetails();
@endphp

<x-member::panel :title="__('Bank transfer details')">
    @if ($configured)
        <p class="mb-3 text-sm text-gray-600">
            {{ __('Use these details when transferring funds to the pool. Include your name or member number in the transfer reference.') }}
        </p>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Bank name') }}</dt>
                <dd class="mt-0.5 font-semibold text-gray-900">{{ $bankName }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('IBAN') }}</dt>
                <dd class="mt-0.5 break-all font-mono text-sm font-semibold tracking-wide text-gray-900" dir="ltr">
                    {{ $iban }}
                </dd>
            </div>
        </dl>
    @else
        <x-member::notice tone="amber">
            <p class="m-0">
                {{ __('Bank transfer details have not been configured yet—please contact the fund administrators.') }}
            </p>
        </x-member::notice>
    @endif
</x-member::panel>