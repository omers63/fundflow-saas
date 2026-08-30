@php
    $projectedFundAtStart = (float) ($projection['projected_fund'] ?? 0);
    $loanRepaymentCycles = (int) ($projection['loan_repayment_cycles'] ?? 0);
    $contributionCycles = (int) ($projection['cycles_added'] ?? 0);
    $loanInstallment = $projection['loan_repayment_installment'] ?? null;
    $loanRepaymentAmount = (float) ($projection['loan_repayment_amount'] ?? 0);
    $settlementIncluded = (float) ($projection['settlement_included_amount'] ?? 0);
    $cashNeeded = (float) ($projection['cash_needed'] ?? 0);
    $settlementMode = (string) ($projection['loan_settlement_mode'] ?? '');
    $isEarlyToMaturity = $settlementMode === \App\Support\LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY
        && abs($loanRepaymentAmount) > 0.00001;
    $isFullEarlySettlement = $settlementMode === \App\Support\LoanCalculatorCurrentLoanSettlement::FULL_EARLY_SETTLEMENT
        && abs($loanRepaymentAmount) > 0.00001;
@endphp

<div class="rounded-xl border border-gray-200/80 bg-white/80 p-3 ring-1 ring-gray-100 dark:border-gray-700 dark:bg-gray-900/20 dark:ring-gray-800">
    <div class="ff-member-loan-calc-projected-fund rounded-lg bg-emerald-50/80 p-3 ring-1 ring-emerald-200/80 dark:bg-emerald-950/30 dark:ring-emerald-800/60">
        <div class="ff-member-loan-calc-projected-fund__split">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                    {{ __('Projected fund at start') }}
                </p>
                <p @class([
                    'mt-1 text-lg font-bold tabular-nums',
                    'text-emerald-900 dark:text-emerald-100' => $projectedFundAtStart >= 0,
                    'ff-member-amount--danger text-rose-600 dark:text-rose-400' => $projectedFundAtStart < 0,
                ])>
                    <x-member::amount :value="$projectedFundAtStart" :currency="$currency" />
                </p>
                <p class="ff-member-loan-calc-projected-fund__formula mt-1 text-xs text-emerald-700/80 dark:text-emerald-300/80">
                    @if ($isFullEarlySettlement && $contributionCycles > 0)
                        {!! __('Current fund :current + :settlement (full early settlement) + :count × :amount', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                            'count' => e((string) $contributionCycles),
                            'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                        ]) !!}
                    @elseif ($isFullEarlySettlement)
                        {!! __('Current fund :current + :settlement (full early settlement)', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                        ]) !!}
                    @elseif ($isEarlyToMaturity && $contributionCycles > 0)
                        {!! __('Current fund :current + :settlement (early settlement to maturity) + :count × :amount', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                            'count' => e((string) $contributionCycles),
                            'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                        ]) !!}
                    @elseif ($isEarlyToMaturity)
                        {!! __('Current fund :current + :settlement (early settlement to maturity)', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                        ]) !!}
                    @elseif ($loanRepaymentCycles > 0 && $contributionCycles > 0 && $loanInstallment !== null)
                        {!! __('Current fund :current + :loan_count × :installment + :contrib_count × :amount', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'loan_count' => e((string) $loanRepaymentCycles),
                            'installment' => $moneyHtml($loanInstallment),
                            'contrib_count' => e((string) $contributionCycles),
                            'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                        ]) !!}
                    @elseif ($loanRepaymentCycles > 0 && $contributionCycles > 0)
                        {!! __('Current fund :current + :settlement (loan repayments) + :count × :amount', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                            'count' => e((string) $contributionCycles),
                            'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                        ]) !!}
                    @elseif ($loanRepaymentCycles > 0 && $loanInstallment !== null)
                        {!! __('Current fund :current + :loan_count × :installment', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'loan_count' => e((string) $loanRepaymentCycles),
                            'installment' => $moneyHtml($loanInstallment),
                        ]) !!}
                    @elseif ($loanRepaymentCycles > 0)
                        {!! __('Current fund :current + :settlement (loan repayments)', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'settlement' => $moneyHtml($loanRepaymentAmount),
                        ]) !!}
                    @elseif ($contributionCycles > 0)
                        {!! __('Current fund :current + :count × :amount', [
                            'current' => $moneyHtml($projection['current_fund'] ?? 0),
                            'count' => e((string) $contributionCycles),
                            'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                        ]) !!}
                    @else
                        {{ __('Using your current fund balance.') }}
                    @endif
                    @if ($settlementIncluded > 0.00001)
                        {!! __(' + :amount (:percent% settlement threshold)', [
                            'amount' => $moneyHtml($settlementIncluded),
                            'percent' => $settlementPercent,
                        ]) !!}
                    @endif
                </p>
            </div>

            <div class="ff-member-loan-calc-projected-fund__cash min-w-0">
                <p class="text-xs uppercase tracking-wide text-sky-700 dark:text-sky-300">
                    {{ __('Total cash needed') }}
                </p>
                <p class="mt-1 text-lg font-bold tabular-nums text-sky-900 dark:text-sky-100">
                    <x-member::amount :value="$cashNeeded" :currency="$currency" />
                </p>
                <p class="ff-member-loan-calc-projected-fund__formula mt-1 text-xs text-sky-700/80 dark:text-sky-300/80">
                    @if ($cashNeeded <= 0.00001)
                        {{ __('No extra cash needed.') }}
                    @else
                        @if ($isFullEarlySettlement && $contributionCycles > 0)
                            {!! __(':settlement (full early settlement) + :count × :amount', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                                'count' => e((string) $contributionCycles),
                                'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                            ]) !!}
                        @elseif ($isFullEarlySettlement)
                            {!! __(':settlement (full early settlement)', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                            ]) !!}
                        @elseif ($isEarlyToMaturity && $contributionCycles > 0)
                            {!! __(':settlement (early settlement to maturity) + :count × :amount', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                                'count' => e((string) $contributionCycles),
                                'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                            ]) !!}
                        @elseif ($isEarlyToMaturity)
                            {!! __(':settlement (early settlement to maturity)', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                            ]) !!}
                        @elseif ($loanRepaymentCycles > 0 && $contributionCycles > 0 && $loanInstallment !== null)
                            {!! __(':loan_count × :installment + :contrib_count × :amount', [
                                'loan_count' => e((string) $loanRepaymentCycles),
                                'installment' => $moneyHtml($loanInstallment),
                                'contrib_count' => e((string) $contributionCycles),
                                'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                            ]) !!}
                        @elseif ($loanRepaymentCycles > 0 && $contributionCycles > 0)
                            {!! __(':settlement (loan repayments) + :count × :amount', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                                'count' => e((string) $contributionCycles),
                                'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                            ]) !!}
                        @elseif ($loanRepaymentCycles > 0 && $loanInstallment !== null)
                            {!! __(':loan_count × :installment', [
                                'loan_count' => e((string) $loanRepaymentCycles),
                                'installment' => $moneyHtml($loanInstallment),
                            ]) !!}
                        @elseif ($loanRepaymentCycles > 0)
                            {!! __(':settlement (loan repayments)', [
                                'settlement' => $moneyHtml($loanRepaymentAmount),
                            ]) !!}
                        @elseif ($contributionCycles > 0)
                            {!! __('Contributions :count × :amount', [
                                'count' => e((string) $contributionCycles),
                                'amount' => $moneyHtml($projection['contribution_amount'] ?? 0),
                            ]) !!}
                        @endif
                        @if ($settlementIncluded > 0.00001)
                            {!! __(' + :amount (:percent% settlement threshold)', [
                                'amount' => $moneyHtml($settlementIncluded),
                                'percent' => $settlementPercent,
                            ]) !!}
                        @endif
                    @endif
                </p>
                @if ($cashNeeded > 0.00001)
                    <p class="mt-1 text-xs text-sky-700/70 dark:text-sky-300/70">
                        {{ __('Loan settlement and contributions payable in cash to reach this fund.') }}
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
