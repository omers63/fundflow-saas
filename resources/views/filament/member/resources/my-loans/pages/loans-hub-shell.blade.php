@php
$tabs = [
    'active' => __('Active'),
    'history' => __('History'),
    'apply' => __('Apply'),
];
@endphp

<div class="ff-member-loans-hub space-y-4">
    <div class="ff-member-tab-bar flex flex-wrap gap-2 border-b border-gray-200 pb-2">
        @foreach ($tabs as $key => $label)
            <button
                type="button"
                wire:click="setHubTab('{{ $key }}')"
                @class([
                    'ff-member-tab-bar__item rounded-t-lg px-3 py-1.5 text-sm font-semibold transition',
                    'border-b-2 border-primary-600 text-primary-700' => $hubTab === $key,
                    'text-gray-600 hover:text-gray-900' => $hubTab !== $key,
                ])
            >
                <x-ff-tab-pill-label :label="$label" :key="$key" />
                @if ($key === 'active' && $activeCount > 0)
                    <span class="ms-1 text-xs text-gray-500">({{ $activeCount }})</span>
                @endif
                @if ($key === 'history' && $historyCount > 0)
                    <span class="ms-1 text-xs text-gray-500">({{ $historyCount }})</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($hubTab === 'active')
        @if ($settleLoan)
            <x-member::notice tone="blue">
                <p class="m-0">
                    {{ __('Use Pay this period above or Early settlement on your loan card to repay from your cash balance.') }}
                </p>
            </x-member::notice>
        @endif

        @forelse ($activeLoans as $loan)
            @include('filament.member.resources.my-loans.partials.active-loan-card', [
                'loan' => $loan,
                'currency' => $currency,
                'canSettle' => ($loan['status'] ?? null) === 'active',
            ])
        @empty
            <x-member::notice tone="blue">
                <p class="m-0">{{ __('You have no active loan applications or disbursements right now.') }}</p>
                @if ($eligible)
                    <p class="m-0 mt-2">
                        <button type="button" wire:click="setHubTab('apply')" class="font-semibold underline">
                            {{ __('Apply for a loan') }} →
                        </button>
                    </p>
                @endif
            </x-member::notice>
        @endforelse
    @elseif ($hubTab === 'history')
        @forelse ($historyLoans as $loan)
            @include('filament.member.resources.my-loans.partials.active-loan-card', [
                'loan' => $loan,
                'currency' => $currency,
                'showSchedule' => $loan['show_schedule'] ?? false,
                'canSettle' => false,
            ])
        @empty
            <x-member::notice tone="blue">
                <p class="m-0">{{ __('You have no closed or past loans on record.') }}</p>
            </x-member::notice>
        @endforelse
    @elseif ($hubTab === 'apply')
        <x-member::panel :title="__('Apply for a loan')">
            <p class="mb-3 text-sm text-gray-600">
                {{ __('Check your eligibility, choose an amount and guarantor, then submit your application for admin review.') }}
            </p>
            @if ($eligible)
                <x-member::panel-actions>
                    <a href="{{ $applyUrl }}" wire:navigate class="fi-btn fi-btn-size-sm fi-color-primary">
                        {{ __('Start application') }}
                    </a>
                    <a href="{{ $calculatorUrl }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
                        {{ __('Loan calculator') }}
                    </a>
                </x-member::panel-actions>
            @elseif ($hasPendingEligibilityReview ?? false)
                <x-member::notice tone="amber">
                    <p class="m-0">{{ __('An administrator is reviewing your loan eligibility request.') }}</p>
                </x-member::notice>
            @else
                <x-member::notice tone="amber">
                    <p class="m-0">{{ $eligibilityReason ?? __('You are not eligible to apply for a loan at this time.') }}</p>
                    @if ($canRequestEligibilityOverride ?? false)
                        <p class="m-0 mt-3">
                            <button
                                type="button"
                                wire:click="mountAction('requestEligibilityOverride')"
                                class="fi-btn fi-btn-size-sm fi-color-warning"
                            >
                                {{ __('Request eligibility review') }}
                            </button>
                        </p>
                    @endif
                </x-member::notice>
            @endif
        </x-member::panel>
    @endif
</div>
