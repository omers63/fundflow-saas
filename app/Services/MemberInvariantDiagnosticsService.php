<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Support\ContributionPolicySettings;

/**
 * Investigative breakdown for MEMBER_CASH_DRIFT / MEMBER_FUND_DRIFT reconciliation exceptions.
 */
final class MemberInvariantDiagnosticsService
{
    public function __construct(
        private readonly MemberInvariantService $invariants,
    ) {}

    public function supports(ReconciliationException $exception): bool
    {
        return in_array($exception->exception_code, ['MEMBER_CASH_DRIFT', 'MEMBER_FUND_DRIFT'], true)
            && filled($exception->affected_entities['member_id'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forException(ReconciliationException $exception): ?array
    {
        if (! $this->supports($exception)) {
            return null;
        }

        $member = Member::query()->find((int) $exception->affected_entities['member_id']);

        if ($member === null) {
            return null;
        }

        $pool = $exception->exception_code === 'MEMBER_FUND_DRIFT' ? 'fund' : 'cash';

        return $this->forMember($member, $pool);
    }

    /**
     * @return array<string, mixed>
     */
    public function forMember(Member $member, string $pool = 'cash'): array
    {
        $member->loadMissing(['cashAccount', 'fundAccount']);

        $check = $this->invariants->check($member);
        $isCash = $pool === 'cash';

        $expected = $isCash ? (float) $check['expected_cash'] : (float) $check['expected_fund'];
        $actual = $isCash ? (float) $check['actual_cash'] : (float) $check['actual_fund'];
        $drift = $isCash ? (float) $check['cash_drift'] : (float) $check['fund_drift'];
        $tolerance = ContributionPolicySettings::reconTolerance();

        $formulaLines = $this->formulaLines($check['components'], $pool);
        $uncounted = $isCash
            ? $this->uncountedCashFlows($member)
            : $this->uncountedFundFlows($member);

        $uncountedNet = $this->uncountedNet($uncounted);
        $adjustedExpected = round($expected + $uncountedNet, 2);
        $adjustedDrift = round(abs($adjustedExpected - $actual), 2);
        $legacyPattern = $adjustedDrift <= $tolerance && $drift > $tolerance;

        return [
            'pool' => $pool,
            'pool_label' => $isCash ? __('Member cash') : __('Member fund'),
            'expected' => $expected,
            'actual' => $actual,
            'drift' => $drift,
            'tolerance' => $tolerance,
            'formula_lines' => $formulaLines,
            'uncounted_flows' => $uncounted,
            'uncounted_net' => $uncountedNet,
            'adjusted_expected' => $adjustedExpected,
            'adjusted_drift' => $adjustedDrift,
            'legacy_import_pattern' => $legacyPattern,
            'mismatch_transactions' => $isCash
                ? $this->mismatchCashTransactions($member, $uncounted)
                : $this->mismatchFundTransactions($member),
            'suggested_correction' => $this->suggestedCorrection(
                $pool,
                $expected,
                $actual,
                $drift,
                $legacyPattern,
                $adjustedExpected,
            ),
        ];
    }

    /**
     * @param  array<string, float>  $components
     * @return list<array{label: string, sign: string, amount: float, kind: string}>
     */
    private function formulaLines(array $components, string $pool): array
    {
        $definitions = $pool === 'cash'
            ? [
                ['key' => 'opening_cash', 'label' => __('Opening cash'), 'sign' => '+'],
                ['key' => 'deposits_received', 'label' => __('Deposits received'), 'sign' => '+'],
                ['key' => 'subscription_deposits', 'label' => __('Subscription deposits'), 'sign' => '+'],
                ['key' => 'loan_disbursements_credited', 'label' => __('Loan disbursements credited'), 'sign' => '+'],
                ['key' => 'direct_bank_imports_posted', 'label' => __('Direct bank imports posted'), 'sign' => '+'],
                ['key' => 'dependent_transfers_in', 'label' => __('Dependent transfers in'), 'sign' => '+'],
                ['key' => 'refunds_and_recon_credits', 'label' => __('Refunds and recon credits'), 'sign' => '+'],
                ['key' => 'contributions_debited', 'label' => __('Contributions debited'), 'sign' => '−'],
                ['key' => 'emi_debited', 'label' => __('EMI debited (installment ref.)'), 'sign' => '−'],
                ['key' => 'subscription_fees_debited', 'label' => __('Subscription fees debited'), 'sign' => '−'],
                ['key' => 'late_fees_net', 'label' => __('Late fees (net)'), 'sign' => '−'],
                ['key' => 'cash_outs', 'label' => __('Cash outs'), 'sign' => '−'],
                ['key' => 'dependent_transfers_out', 'label' => __('Dependent transfers out'), 'sign' => '−'],
            ]
            : [
                ['key' => 'opening_fund', 'label' => __('Opening fund'), 'sign' => '+'],
                ['key' => 'contributions_collected', 'label' => __('Contributions collected'), 'sign' => '+'],
                ['key' => 'contribution_fund_reversals', 'label' => __('Contribution fund reversals'), 'sign' => '−'],
                ['key' => 'loan_disbursements_from_fund', 'label' => __('Loan disbursements from fund'), 'sign' => '−'],
                ['key' => 'guarantor_fund_debits', 'label' => __('Guarantor fund debits'), 'sign' => '−'],
                ['key' => 'emi_repayments', 'label' => __('EMI repayments (installment ref.)'), 'sign' => '+'],
            ];

        $lines = [];

        foreach ($definitions as $definition) {
            $amount = (float) ($components[$definition['key']] ?? 0);

            if (abs($amount) < 0.00001) {
                continue;
            }

            $lines[] = [
                'label' => $definition['label'],
                'sign' => $definition['sign'],
                'amount' => $amount,
                'kind' => 'component',
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, sign: string, amount: float, detail: string}>
     */
    private function uncountedCashFlows(Member $member): array
    {
        $accountId = $member->cashAccount?->id;

        if ($accountId === null) {
            return [];
        }

        $flows = [];

        $contributionCredits = $this->sumByReference($accountId, (int) $member->id, Contribution::class, 'credit');

        if ($contributionCredits > 0.00001) {
            $flows[] = [
                'label' => __('Contribution cash credits'),
                'sign' => '+',
                'amount' => $contributionCredits,
                'detail' => __('Legacy import and some collection flows credit member cash before debiting for the contribution. The §5.13 formula counts only contribution debits.'),
            ];
        }

        $repaymentCredits = $this->sumByReference($accountId, (int) $member->id, LoanRepayment::class, 'credit');
        $repaymentDebits = $this->sumByReference($accountId, (int) $member->id, LoanRepayment::class, 'debit');

        if ($repaymentCredits > 0.00001) {
            $flows[] = [
                'label' => __('Loan repayment cash credits'),
                'sign' => '+',
                'amount' => $repaymentCredits,
                'detail' => __('Imported repayments credit member cash via LoanRepayment references. The formula counts EMI debits on LoanInstallment references only.'),
            ];
        }

        if ($repaymentDebits > 0.00001) {
            $flows[] = [
                'label' => __('Loan repayment cash debits'),
                'sign' => '−',
                'amount' => $repaymentDebits,
                'detail' => __('Paired repayment debits on LoanRepayment references are not included in the EMI debit component.'),
            ];
        }

        return $flows;
    }

    /**
     * @return list<array{label: string, sign: string, amount: float, detail: string}>
     */
    private function uncountedFundFlows(Member $member): array
    {
        $accountId = $member->fundAccount?->id;

        if ($accountId === null) {
            return [];
        }

        $flows = [];

        $repaymentCredits = $this->sumByReference($accountId, (int) $member->id, LoanRepayment::class, 'credit');
        $repaymentDebits = $this->sumByReference($accountId, (int) $member->id, LoanRepayment::class, 'debit');

        if ($repaymentCredits > 0.00001) {
            $flows[] = [
                'label' => __('Loan repayment fund credits'),
                'sign' => '+',
                'amount' => $repaymentCredits,
                'detail' => __('Fund credits on LoanRepayment references are not counted in the installment repayment component.'),
            ];
        }

        if ($repaymentDebits > 0.00001) {
            $flows[] = [
                'label' => __('Loan repayment fund debits'),
                'sign' => '−',
                'amount' => $repaymentDebits,
                'detail' => __('Fund debits on LoanRepayment references are not counted in the installment repayment component.'),
            ];
        }

        return $flows;
    }

    /**
     * @param  list<array{label: string, sign: string, amount: float, detail?: string}>  $uncounted
     */
    private function uncountedNet(array $uncounted): float
    {
        $net = 0.0;

        foreach ($uncounted as $flow) {
            $net += $flow['sign'] === '+'
                ? (float) $flow['amount']
                : -(float) $flow['amount'];
        }

        return round($net, 2);
    }

    /**
     * @param  list<array{label: string, sign: string, amount: float, detail: string}>  $uncounted
     * @return list<array{id: int, date: string, type: string, amount: float, description: string, category: string}>
     */
    private function mismatchCashTransactions(Member $member, array $uncounted): array
    {
        if ($uncounted === []) {
            return [];
        }

        $accountId = $member->cashAccount?->id;

        if ($accountId === null) {
            return [];
        }

        $contributionMorph = (new Contribution)->getMorphClass();
        $repaymentMorph = (new LoanRepayment)->getMorphClass();

        return Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $member->id)
            ->where(function ($query) use ($contributionMorph, $repaymentMorph): void {
                $query->where(function ($builder) use ($contributionMorph): void {
                    $builder->where('reference_type', $contributionMorph)
                        ->where('type', 'credit');
                })->orWhere('reference_type', $repaymentMorph);
            })
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(function (Transaction $transaction) use ($contributionMorph): array {
                $category = $transaction->reference_type === $contributionMorph
                    ? __('Contribution cash credit (uncounted)')
                    : __('Loan repayment cash leg (uncounted)');

                return [
                    'id' => (int) $transaction->id,
                    'date' => $transaction->transacted_at?->format('Y-m-d') ?? '—',
                    'type' => (string) $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'description' => (string) $transaction->description,
                    'category' => $category,
                ];
            })
            ->all();
    }

    /**
     * @return list<array{id: int, date: string, type: string, amount: float, description: string, category: string}>
     */
    private function mismatchFundTransactions(Member $member): array
    {
        $accountId = $member->fundAccount?->id;

        if ($accountId === null) {
            return [];
        }

        $repaymentMorph = (new LoanRepayment)->getMorphClass();

        return Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $member->id)
            ->where('reference_type', $repaymentMorph)
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => (int) $transaction->id,
                'date' => $transaction->transacted_at?->format('Y-m-d') ?? '—',
                'type' => (string) $transaction->type,
                'amount' => (float) $transaction->amount,
                'description' => (string) $transaction->description,
                'category' => __('Loan repayment fund leg (uncounted)'),
            ])
            ->all();
    }

    /**
     * @return array{action: string, direction: ?string, amount: float, summary: string, caution: ?string}
     */
    private function suggestedCorrection(
        string $pool,
        float $expected,
        float $actual,
        float $drift,
        bool $legacyPattern,
        float $adjustedExpected,
    ): array {
        if ($drift <= ContributionPolicySettings::reconTolerance()) {
            return [
                'action' => 'none',
                'direction' => null,
                'amount' => 0.0,
                'summary' => __('No correction is required — drift is within tolerance.'),
                'caution' => null,
            ];
        }

        if ($legacyPattern) {
            return [
                'action' => 'resolve',
                'direction' => null,
                'amount' => 0.0,
                'summary' => __('Resolve with notes documenting a legacy import paired-cash pattern. Adjusted expected (:adjusted) matches actual (:actual).', [
                    'adjusted' => number_format($adjustedExpected, 2),
                    'actual' => number_format($actual, 2),
                ]),
                'caution' => __('Do not post a cash correction — the stored balance is correct. The variance is from ledger legs excluded by the current formula.'),
            ];
        }

        $direction = $expected > $actual ? 'credit' : 'debit';
        $poolLabel = $pool === 'cash' ? __('member cash') : __('member fund');

        return [
            'action' => 'post_correction',
            'direction' => $direction,
            'amount' => round($drift, 2),
            'summary' => $direction === 'credit'
                ? __('Credit :pool by :amount with master mirror to align actual (:actual) to expected (:expected).', [
                    'pool' => $poolLabel,
                    'amount' => number_format($drift, 2),
                    'actual' => number_format($actual, 2),
                    'expected' => number_format($expected, 2),
                ])
                : __('Debit :pool by :amount with master mirror to align actual (:actual) to expected (:expected).', [
                    'pool' => $poolLabel,
                    'amount' => number_format($drift, 2),
                    'actual' => number_format($actual, 2),
                    'expected' => number_format($expected, 2),
                ]),
            'caution' => null,
        ];
    }

    private function sumByReference(int $accountId, int $memberId, string $referenceClass, string $type): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('reference_type', (new $referenceClass)->getMorphClass())
            ->sum('amount');
    }
}
