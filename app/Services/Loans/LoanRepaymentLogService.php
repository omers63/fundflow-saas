<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Support\BusinessDay;
use App\Support\LoanRepaymentNote;
use Carbon\CarbonInterface;

final class LoanRepaymentLogService
{
    private static bool $suppressInstallmentLogs = false;

    public static function isSuppressingInstallmentLogs(): bool
    {
        return self::$suppressInstallmentLogs;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runSuppressingInstallmentLogs(callable $callback): mixed
    {
        self::$suppressInstallmentLogs = true;

        try {
            return $callback();
        } finally {
            self::$suppressInstallmentLogs = false;
        }
    }

    public function recordInstallmentRepayment(LoanInstallment $installment): LoanRepayment
    {
        $installment->loadMissing('loan');

        $principal = (float) ($installment->amount_collected > 0
            ? $installment->amount_collected
            : $installment->amount);
        $lateFee = (float) ($installment->late_fee_amount ?? 0);

        return LoanRepayment::query()->create([
            'loan_id' => $installment->loan_id,
            'amount' => round($principal + $lateFee, 2),
            'paid_at' => $installment->paid_at ?? BusinessDay::now(),
            'notes' => LoanRepaymentNote::installment((int) $installment->installment_number),
        ]);
    }

    public function recordSettlementRepayment(
        Loan $loan,
        float $amount,
        string $kind,
        ?string $option = null,
        ?CarbonInterface $paidAt = null,
    ): LoanRepayment {
        $notes = match ($kind) {
            'full' => LoanRepaymentNote::fullEarlySettlement(),
            'partial' => LoanRepaymentNote::partialEarlySettlement((string) $option),
            default => LoanRepaymentNote::PREFIX.'settlement:'.$kind,
        };

        $at = $paidAt ?? BusinessDay::now();

        return LoanRepayment::query()->create([
            'loan_id' => $loan->id,
            'amount' => round($amount, 2),
            'paid_at' => $at,
            'notes' => $notes,
        ]);
    }

    /**
     * Display-only history for early-settled loans that never got a settlement log row.
     *
     * Do not invent a settlement line when repayment history already exists (legacy import /
     * EMI observer rows) — that double-counts against paid installments in reconciliation.
     * Completed (non-early) loans are never backfilled as "Full early settlement".
     */
    public function backfillSettlementRepaymentIfMissing(Loan $loan): ?LoanRepayment
    {
        if ($loan->status !== 'early_settled' || $loan->settled_at === null) {
            return null;
        }

        if ($loan->repayments()->exists()) {
            return null;
        }

        $settledAt = $loan->settled_at;

        $amount = (float) $loan->installments()
            ->where(function ($query) use ($settledAt): void {
                $query->whereDate('paid_at', $settledAt->toDateString())
                    ->orWhereDate('waived_at', $settledAt->toDateString());
            })
            ->get()
            ->sum(function (LoanInstallment $installment): float {
                $principal = (float) ($installment->amount_collected > 0
                    ? $installment->amount_collected
                    : ($installment->isPaid() ? $installment->amount : 0));
                $lateFee = (float) ($installment->late_fee_amount ?? 0);

                return $principal + $lateFee;
            });

        if ($amount <= 0.00001) {
            $amount = (float) $loan->installments()
                ->whereIn('status', ['paid', 'waived'])
                ->get()
                ->sum(fn (LoanInstallment $installment): float => (float) $installment->collectedCashAmount()
                    + (float) ($installment->late_fee_amount ?? 0));
        }

        if ($amount <= 0.00001) {
            return null;
        }

        return $this->recordSettlementRepayment(
            $loan,
            $amount,
            'full',
            null,
            $settledAt,
        );
    }

    /**
     * Remove synthetic "Full early settlement" rows on completed loans that already had
     * repayment history (typical legacy-import false backfill).
     *
     * Live early settlements use status `early_settled` and are left alone even when
     * earlier EMI repayment logs exist.
     *
     * @return int Number of repayment rows deleted
     */
    public function pruneSpuriousCompletedSettlementBackfills(?Loan $loan = null): int
    {
        $query = LoanRepayment::query()
            ->where('notes', 'like', '%settlement:%')
            ->whereHas('loan', function ($loanQuery) use ($loan): void {
                $loanQuery->where('status', 'completed');

                if ($loan !== null) {
                    $loanQuery->whereKey($loan->getKey());
                }
            });

        $deleted = 0;

        foreach ($query->get() as $repayment) {
            $hasSiblingRepayments = LoanRepayment::query()
                ->where('loan_id', $repayment->loan_id)
                ->whereKeyNot($repayment->getKey())
                ->exists();

            if (! $hasSiblingRepayments) {
                continue;
            }

            $repayment->delete();
            $deleted++;
        }

        return $deleted;
    }
}
