<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves which loan a historical legacy payment should repay, using the same
 * cumulative 50/50 + 16% target windows as {@see LegacyPaymentClassifierService}.
 */
final class LegacyLoanRepaymentWindowResolver
{
    public function __construct(
        private readonly LegacyMigrationDatabaseLoanResolver $loanResolver,
    ) {}

    public function resolveLoan(
        Member $member,
        CarbonInterface $paymentDate,
        float $amount,
        array &$cumulativeRepaidByLoanKey,
        ?LegacyMigrationCsvLoanIndex $loanIndex = null,
    ): ?Loan {
        if ($amount <= 0.00001) {
            return null;
        }

        $window = $this->resolveWindow(
            LegacyPaymentClassifyMember::fromDatabase($member),
            Carbon::parse($paymentDate),
            $cumulativeRepaidByLoanKey,
            $loanIndex,
        );

        if ($window?->loanId === null) {
            return null;
        }

        return Loan::query()->find($window->loanId);
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public function resolveWindow(
        LegacyPaymentClassifyMember $member,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
        ?LegacyMigrationCsvLoanIndex $loanIndex = null,
    ): ?LegacyLoanRepaymentWindow {
        if ($member->databaseMember !== null) {
            return $this->resolveDatabaseWindow($member->databaseMember, $paymentDate, $cumulativeRepaidByLoanKey);
        }

        $window = $loanIndex?->repaymentWindowAt($member->memberNumber, $paymentDate, $cumulativeRepaidByLoanKey);

        if ($window === null) {
            return null;
        }

        return $this->loanResolver->enrichWindow($window, $member->memberNumber);
    }

    public function recordRepayment(Loan $loan, Member $member, float $amount, array &$cumulativeRepaidByLoanKey): void
    {
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();
        $loanKey = LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt);

        $cumulativeRepaidByLoanKey[$loanKey] = round(
            ($cumulativeRepaidByLoanKey[$loanKey] ?? 0.0) + $amount,
            2,
        );
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function resolveDatabaseWindow(
        Member $member,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
    ): ?LegacyLoanRepaymentWindow {
        foreach ($member->loans()
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->whereNotNull('disbursed_at')
            ->where('disbursed_at', '<=', $paymentDate)
            ->orderBy('disbursed_at')
            ->get() as $loan) {
            $window = $this->buildDatabaseWindow($member, $loan);

            if (! $window->isDisbursedOnOrBefore($paymentDate)) {
                continue;
            }

            $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

            if ($window->hasRemainingRepayment($cumulative)) {
                return $window;
            }
        }

        return null;
    }

    private function buildDatabaseWindow(Member $member, Loan $loan): LegacyLoanRepaymentWindow
    {
        $approved = (float) ($loan->amount_approved ?? $loan->amount);
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt),
            disbursedAt: $disbursedAt,
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::totalRepaymentDue($approved),
            loanId: $loan->id,
        );
    }
}
