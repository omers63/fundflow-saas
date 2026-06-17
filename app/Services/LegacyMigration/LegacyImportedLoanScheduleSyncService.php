<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Applies bulk-imported {@see LoanRepayment} rows to installment schedules without
 * posting additional ledger entries (ledger was posted at import time).
 *
 * Repayments may sit on the wrong loan when legacy import lacked loan_number;
 * each payment is applied chronologically to the member's earliest disbursed loan with
 * unpaid installments as of that payment date.
 */
final class LegacyImportedLoanScheduleSyncService
{
    private const float AMOUNT_TOLERANCE = 0.02;

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

        $repayments = LoanRepayment::query()
            ->whereIn('loan_id', $loans->pluck('id'))
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        if ($repayments->isEmpty()) {
            return ['loans' => 0, 'installments' => 0];
        }

        $installmentsByLoan = LoanInstallment::query()
            ->whereIn('loan_id', $loans->pluck('id'))
            ->orderBy('installment_number')
            ->get()
            ->groupBy('loan_id');

        /** @var array<int, array{status: string, paid_at: CarbonInterface|string|null}> $updates */
        $updates = [];
        $touchedLoanIds = [];

        foreach ($repayments as $repayment) {
            $pool = (float) $repayment->amount;
            $paidAt = $repayment->paid_at;

            foreach ($loans as $loan) {
                if ($loan->disbursed_at !== null && $paidAt->lt($loan->disbursed_at)) {
                    continue;
                }

                /** @var Collection<int, LoanInstallment> $installments */
                $installments = $installmentsByLoan->get($loan->id, collect());

                foreach ($installments as $installment) {
                    if ($installment->isPaid() || isset($updates[$installment->id])) {
                        continue;
                    }

                    $needed = (float) $installment->amount;

                    if ($pool + self::AMOUNT_TOLERANCE < $needed) {
                        break 2;
                    }

                    $pool -= $needed;
                    $updates[$installment->id] = [
                        'status' => 'paid',
                        'paid_at' => $paidAt,
                    ];
                    $touchedLoanIds[$loan->id] = true;

                    if ($pool <= self::AMOUNT_TOLERANCE) {
                        break 2;
                    }
                }
            }
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
            $loan->fresh()->syncPaidOffStatusFromInstallments();
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
}
