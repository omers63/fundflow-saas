<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Applies bulk-imported {@see LoanRepayment} rows to installment schedules without
 * posting additional ledger entries (ledger was posted at import time).
 *
 * Repayments are applied to the member's active repayment window at each payment
 * date (same cumulative 50/50 + 16% logic as the classifier), not blindly by
 * {@see LoanRepayment::$loan_id}. Overpayments spill to the next loan window.
 */
final class LegacyImportedLoanScheduleSyncService
{
    private const float AMOUNT_TOLERANCE = 0.02;

    public function __construct(
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
    ) {
    }

    /**
     * @param  iterable<int|string>  $loanIds
     * @return array{loans: int, installments: int}
     */
    public function syncLoans(iterable $loanIds): array
    {
        $memberIds = Loan::query()
            ->whereIn('id', Collection::make($loanIds)->unique()->filter()->all())
            ->pluck('member_id')
            ->unique();

        return $this->syncMembers($memberIds);
    }

    /**
     * @param  iterable<int|string>  $memberIds
     * @return array{loans: int, installments: int}
     */
    public function syncMembers(iterable $memberIds): array
    {
        $loansSynced = 0;
        $installmentsMarked = 0;

        foreach (Collection::make($memberIds)->unique()->filter() as $memberId) {
            $member = Member::query()->find($memberId);

            if ($member === null) {
                continue;
            }

            $result = $this->syncMemberLoans($member);
            $loansSynced += $result['loans'];
            $installmentsMarked += $result['installments'];
        }

        return [
            'loans' => $loansSynced,
            'installments' => $installmentsMarked,
        ];
    }

    /**
     * Sync every member who has at least one imported repayment on any of their loans.
     *
     * @return array{loans: int, installments: int}
     */
    public function syncAllLoansWithImportedRepayments(): array
    {
        $memberIds = Loan::query()
            ->whereHas('repayments')
            ->distinct()
            ->pluck('member_id');

        return $this->syncMembers($memberIds);
    }

    /**
     * @return array{loans: int, installments: int}
     */
    public function syncMemberLoans(Member $member): array
    {
        $loans = $member->loans()
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->whereNotNull('disbursed_at')
            ->orderBy('disbursed_at')
            ->get();

        if ($loans->isEmpty()) {
            return ['loans' => 0, 'installments' => 0];
        }

        $loanIds = $loans->pluck('id');

        $repayments = LoanRepayment::query()
            ->whereIn('loan_id', $loanIds)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        if ($repayments->isEmpty()) {
            return ['loans' => 0, 'installments' => 0];
        }

        $this->resetInstallmentPaymentState($loanIds);

        $installmentsByLoan = LoanInstallment::query()
            ->whereIn('loan_id', $loanIds)
            ->orderBy('installment_number')
            ->get()
            ->groupBy('loan_id');

        /** @var array<int, array{status: string, paid_at: CarbonInterface|string|null}> $updates */
        $updates = [];
        $touchedLoanIds = [];
        /** @var array<string, float> $cumulativeRepaidByLoanKey */
        $cumulativeRepaidByLoanKey = [];
        /** @var array<int, float> $pendingInstallmentPoolByLoanId */
        $pendingInstallmentPoolByLoanId = [];

        foreach ($repayments as $repayment) {
            $this->allocateRepaymentAcrossWindows(
                $member,
                $loans,
                (float) $repayment->amount,
                Carbon::parse($repayment->paid_at),
                $cumulativeRepaidByLoanKey,
                $installmentsByLoan,
                $updates,
                $touchedLoanIds,
                $pendingInstallmentPoolByLoanId,
                (int) $repayment->loan_id,
            );
        }

        if ($updates !== []) {
            LoanInstallment::withoutEvents(function () use ($updates): void {
                foreach ($updates as $installmentId => $attributes) {
                    LoanInstallment::query()
                        ->whereKey($installmentId)
                        ->update($attributes);
                }
            });
        }

        foreach ($loans as $loan) {
            $this->syncLoanSettlement($loan->fresh());
        }

        return [
            'loans' => count($touchedLoanIds),
            'installments' => count($updates),
        ];
    }

    public function syncLoan(Loan $loan): int
    {
        $member = $loan->member;

        if ($member === null) {
            return 0;
        }

        return $this->syncMemberLoans($member)['installments'];
    }

    /**
     * @param  Collection<int, Loan>  $loans
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @param  Collection<int, Collection<int, LoanInstallment>>  $installmentsByLoan
     * @param  array<int, array{status: string, paid_at: CarbonInterface|string|null}>  $updates
     * @param  array<int, bool>  $touchedLoanIds
     * @param  array<int, float>  $pendingInstallmentPoolByLoanId
     */
    private function allocateRepaymentAcrossWindows(
        Member $member,
        Collection $loans,
        float $amount,
        CarbonInterface $paidAt,
        array &$cumulativeRepaidByLoanKey,
        Collection $installmentsByLoan,
        array &$updates,
        array &$touchedLoanIds,
        array &$pendingInstallmentPoolByLoanId,
        ?int $preferredLoanId = null,
    ): void {
        $remaining = round($amount, 2);

        if ($preferredLoanId !== null) {
            $preferredLoan = $loans->firstWhere('id', $preferredLoanId);

            if ($preferredLoan !== null && $this->paymentAppliesToLoan($preferredLoan, $paidAt)) {
                $remaining = $this->allocateChunkToLoanWindow(
                    $member,
                    $preferredLoan,
                    $remaining,
                    $paidAt,
                    $cumulativeRepaidByLoanKey,
                    $installmentsByLoan,
                    $updates,
                    $touchedLoanIds,
                    $pendingInstallmentPoolByLoanId,
                );
            }
        }

        while ($remaining > self::AMOUNT_TOLERANCE) {
            $window = $this->repaymentWindowResolver->resolveWindow(
                LegacyPaymentClassifyMember::fromDatabase($member),
                Carbon::parse($paidAt),
                $cumulativeRepaidByLoanKey,
            );

            if ($window === null || $window->loanId === null) {
                break;
            }

            $loan = $loans->firstWhere('id', $window->loanId);

            if ($loan === null || !$this->paymentAppliesToLoan($loan, $paidAt)) {
                break;
            }

            $remaining = $this->allocateChunkToLoanWindow(
                $member,
                $loan,
                $remaining,
                $paidAt,
                $cumulativeRepaidByLoanKey,
                $installmentsByLoan,
                $updates,
                $touchedLoanIds,
                $pendingInstallmentPoolByLoanId,
            );
        }
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @param  Collection<int, Collection<int, LoanInstallment>>  $installmentsByLoan
     * @param  array<int, array{status: string, paid_at: CarbonInterface|string|null}>  $updates
     * @param  array<int, bool>  $touchedLoanIds
     * @param  array<int, float>  $pendingInstallmentPoolByLoanId
     */
    private function allocateChunkToLoanWindow(
        Member $member,
        Loan $loan,
        float $remaining,
        CarbonInterface $paidAt,
        array &$cumulativeRepaidByLoanKey,
        Collection $installmentsByLoan,
        array &$updates,
        array &$touchedLoanIds,
        array &$pendingInstallmentPoolByLoanId,
    ): float {
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();
        $loanKey = LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt, (int) $loan->id);
        $cumulative = $cumulativeRepaidByLoanKey[$loanKey] ?? 0.0;
        $target = LegacyLoanRepaymentTarget::totalRepaymentDue((float) ($loan->amount_approved ?? $loan->amount));
        $windowCap = round(max(0.0, $target - $cumulative), 2);

        if ($windowCap <= self::AMOUNT_TOLERANCE) {
            return $remaining;
        }

        $chunk = round(min($remaining, $windowCap), 2);

        $this->applyPoolToLoanInstallments(
            (int) $loan->id,
            $chunk,
            $paidAt,
            $installmentsByLoan,
            $updates,
            $touchedLoanIds,
            $pendingInstallmentPoolByLoanId,
        );

        $this->repaymentWindowResolver->recordRepayment(
            $loan,
            $member,
            $chunk,
            $cumulativeRepaidByLoanKey,
        );

        return round($remaining - $chunk, 2);
    }

    private function syncLoanSettlement(Loan $loan): void
    {
        if (!in_array($loan->status, ['active', 'transferred'], true)) {
            return;
        }

        if (!$loan->installments()->exists()) {
            return;
        }

        $target = LegacyLoanRepaymentTarget::totalRepaymentDue((float) ($loan->amount_approved ?? $loan->amount));
        $paidInstallmentSum = (float) $loan->installments()->where('status', 'paid')->sum('amount');
        $repaidOnLoan = (float) $loan->repayments()->sum('amount');
        $hasUnpaid = $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->exists();

        $shouldComplete = !$hasUnpaid
            || $paidInstallmentSum + self::AMOUNT_TOLERANCE >= $target
            || $repaidOnLoan + self::AMOUNT_TOLERANCE >= $target;

        if (!$shouldComplete) {
            return;
        }

        $settledAt = $loan->repayments()->max('paid_at')
            ?? $loan->installments()->whereNotNull('paid_at')->max('paid_at')
            ?? BusinessDay::now();

        if ($hasUnpaid) {
            $this->settleRemainingInstallments($loan, $settledAt);
        }

        $loan->update([
            'status' => 'completed',
            'settled_at' => $settledAt,
        ]);
    }

    private function settleRemainingInstallments(Loan $loan, CarbonInterface|string $settledAt): void
    {
        LoanInstallment::withoutEvents(function () use ($loan, $settledAt): void {
            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->update([
                    'status' => 'paid',
                    'paid_at' => $settledAt,
                    'is_late' => false,
                    'late_fee_amount' => 0,
                    'late_fee_tier' => 0,
                    'overdue_since' => null,
                ]);
        });
    }

    /**
     * @param  Collection<int, int|string>  $loanIds
     */
    private function resetInstallmentPaymentState(Collection $loanIds): void
    {
        LoanInstallment::query()
            ->whereIn('loan_id', $loanIds)
            ->where('status', 'paid')
            ->update([
                'status' => 'pending',
                'paid_at' => null,
                'is_late' => false,
                'late_fee_amount' => 0,
                'late_fee_tier' => 0,
                'overdue_since' => null,
            ]);

        Loan::query()
            ->whereIn('id', $loanIds)
            ->where('status', 'completed')
            ->update([
                'status' => 'active',
                'settled_at' => null,
            ]);
    }

    private function paymentAppliesToLoan(Loan $loan, CarbonInterface $paidAt): bool
    {
        return $loan->disbursed_at === null || !$paidAt->lt($loan->disbursed_at);
    }

    /**
     * @param  Collection<int, Collection<int, LoanInstallment>>  $installmentsByLoan
     * @param  array<int, array{status: string, paid_at: CarbonInterface|string|null}>  $updates
     * @param  array<int, bool>  $touchedLoanIds
     * @param  array<int, float>  $pendingInstallmentPoolByLoanId
     */
    private function applyPoolToLoanInstallments(
        int $loanId,
        float $chunk,
        CarbonInterface $paidAt,
        Collection $installmentsByLoan,
        array &$updates,
        array &$touchedLoanIds,
        array &$pendingInstallmentPoolByLoanId,
    ): void {
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = $installmentsByLoan->get($loanId, collect());

        $pool = round(($pendingInstallmentPoolByLoanId[$loanId] ?? 0.0) + $chunk, 2);

        foreach ($installments as $installment) {
            if ($installment->isPaid() || isset($updates[$installment->id])) {
                continue;
            }

            $needed = (float) $installment->amount;

            if ($pool + self::AMOUNT_TOLERANCE < $needed) {
                break;
            }

            $pool = round($pool - $needed, 2);
            $updates[$installment->id] = [
                'status' => 'paid',
                'paid_at' => $paidAt,
            ];
            $touchedLoanIds[$loanId] = true;
        }

        $pendingInstallmentPoolByLoanId[$loanId] = $pool;
    }
}
