<x-filament-panels::page>
    <div class="ff-member-cash-account space-y-4">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-member::panel :title="__('Cash balance')">
                <div class="ff-member-dashboard-balance mb-3">
                    <x-member::amount :value="$balance" :currency="$currency"
                        class="ff-member-dashboard-balance__value" />
                    <p class="ff-member-dashboard-balance__label">{{ __('Current balance') }}</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ __('Available to withdraw') }}
                        </p>
                        <div class="mt-1">
                            <x-member::amount :value="$available" :currency="$currency" />
                        </div>
                    </div>
                    @if (filled($reserved))
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">
                                {{ __('Reserved (next EMI)') }}
                            </p>
                            <div class="mt-1">
                                <x-member::amount :value="$reserved" :currency="$currency" />
                            </div>
                        </div>
                    @endif
                </div>

                @if (filled($memberNumber))
                    <p class="ff-member-dashboard-meta mt-3 mb-0">
                        {{ __('Member') }}: {{ $memberNumber }}
                    </p>
                @endif
            </x-member::panel>

            <div class="space-y-4">
                @include('partials.fund-bank-details')

                <x-member::panel :title="__('Submit a deposit')" id="deposit">
                    <p class="mb-3 text-sm text-gray-600">
                        {{ __('Tell us about a bank transfer you made. An administrator will review and credit your cash account.') }}
                    </p>
                    <form wire:submit="submitDeposit" class="space-y-4">
                        {{ $this->depositForm }}
                        <x-filament::button type="submit" color="primary">
                            {{ __('Submit deposit') }}
                        </x-filament::button>
                    </form>
                </x-member::panel>
            </div>
        </div>

        <div class="space-y-4">
            @if (filled($accountId))
                @livewire(\App\Filament\Member\Widgets\MemberCashLedgerTableWidget::class, ['accountId' => $accountId], key('member-cash-ledger-' . $accountId))
            @endif
            @livewire(\App\Filament\Member\Widgets\MemberCashDepositsTableWidget::class, key('member-cash-deposits'))
        </div>
    </div>
</x-filament-panels::page>