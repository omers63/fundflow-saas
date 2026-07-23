<x-filament-panels::page>
    <div class="ff-member-fund-account space-y-4">
        <x-member::panel :title="__('Fund account')" class="ff-member-fund-hero">
            <div class="ff-member-dashboard-balance">
                <x-member::amount :value="$balance" :currency="$currency"
                    class="ff-member-dashboard-balance__value ff-member-dashboard-balance__value--fund" />
                <p class="ff-member-dashboard-balance__label ff-member-dashboard-balance__label--fund">
                    {{ __('Accumulated fund balance') }}
                </p>
            </div>
        </x-member::panel>

        <div class="ff-member-fund-account-stats grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-member::stat-card :label="__('Monthly contribution')" :amount="$monthly" :currency="$currency" />
            <x-member::stat-card :label="__('Total contributed')" :amount="$contributionsTotal" :currency="$currency" />
            <x-member::stat-card :label="__('Loan fund debits')" :amount="$loanFundDebits" :currency="$currency" />
            <x-member::stat-card :label="__('Loan cap')" :amount="$maxLoan" :currency="$currency" />
            <x-member::stat-card :label="__('Borrow multiplier')" :value="(string) $borrowMultiplier . '×'" />
            <x-member::stat-card :label="__('Contribution status')" :value="$exemptionLabel" />
            <x-member::stat-card
                :label="__('Open period')"
                :value="$postedThisCycle ? __('Posted') : __('Not posted')"
            />
            <x-member::stat-card :label="__('Pending deposits')" :value="(string) $pendingDeposits" />
        </div>

        <p class="text-sm text-gray-600">
            {{ __(':period — :status', [
    'period' => $cycleLabel,
    'status' => $postedThisCycle ? __('Contribution posted this cycle') : __('Contribution not yet posted this cycle'),
]) }}
        </p>

        <div>
            <a href="{{ $statementsUrl }}" class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary">
                {{ __('Monthly statements') }}
            </a>
        </div>

        @if (filled($accountId))
            @livewire(\App\Filament\Member\Widgets\MemberFundLedgerTableWidget::class, ['accountId' => $accountId], key('member-fund-ledger-' . $accountId))
        @endif
    </div>
</x-filament-panels::page>
