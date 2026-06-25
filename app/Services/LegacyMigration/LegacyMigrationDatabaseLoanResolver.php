<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use Carbon\CarbonInterface;

/**
 * Resolve imported loan IDs from member number and disbursement date.
 */
final class LegacyMigrationDatabaseLoanResolver
{
    public function findLoanId(string $memberNumber, CarbonInterface $disbursedAt): ?int
    {
        $memberNumber = trim($memberNumber);

        if ($memberNumber === '') {
            return null;
        }

        $loanId = Loan::query()
            ->whereHas('member', fn($query) => $query->where('member_number', $memberNumber))
            ->whereDate('disbursed_at', $disbursedAt->toDateString())
            ->orderBy('id')
            ->value('id');

        return $loanId !== null ? (int) $loanId : null;
    }

    public function enrichWindow(LegacyLoanRepaymentWindow $window, string $memberNumber): LegacyLoanRepaymentWindow
    {
        if ($window->loanId !== null && Loan::query()->whereKey($window->loanId)->exists()) {
            return $window;
        }

        $loanId = $this->findLoanId($memberNumber, $window->disbursedAt);

        if ($loanId === null) {
            return $window;
        }

        return new LegacyLoanRepaymentWindow(
            loanKey: $window->loanKey,
            disbursedAt: $window->disbursedAt,
            amountApproved: $window->amountApproved,
            repaymentTargetAmount: $window->repaymentTargetAmount,
            firstRepaymentAt: $window->firstRepaymentAt,
            loanId: $loanId,
            graceCycles: $window->graceCycles,
            memberNumber: $window->memberNumber ?? $memberNumber,
            installmentsCount: $window->installmentsCount,
        );
    }
}
