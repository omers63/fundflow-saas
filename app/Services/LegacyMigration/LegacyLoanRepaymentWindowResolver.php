<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Support\LegacyMigrationGraceCycleSettings;
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
    ) {
    }

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
        ?LegacyLoanRepaymentInstallmentTracker $installmentTracker = null,
    ): ?LegacyLoanRepaymentWindow {
        if ($loanIndex !== null && !$loanIndex->isEmpty() && $loanIndex->hasMember($member->memberNumber)) {
            $window = $loanIndex->repaymentWindowAt($member->memberNumber, $paymentDate, $cumulativeRepaidByLoanKey, $installmentTracker);

            if ($window === null) {
                return null;
            }

            return $window;
        }

        if ($member->databaseMember !== null) {
            return $this->resolveDatabaseWindow($member->databaseMember, $paymentDate, $cumulativeRepaidByLoanKey, $installmentTracker);
        }

        $window = $loanIndex?->repaymentWindowAt($member->memberNumber, $paymentDate, $cumulativeRepaidByLoanKey, $installmentTracker);

        if ($window === null) {
            return null;
        }

        return $this->loanResolver->enrichWindow($window, $member->memberNumber);
    }

    public function recordRepayment(Loan $loan, Member $member, float $amount, array &$cumulativeRepaidByLoanKey): void
    {
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();
        $loanKey = LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt, (int) $loan->id);

        $cumulativeRepaidByLoanKey[$loanKey] = round(
            ($cumulativeRepaidByLoanKey[$loanKey] ?? 0.0) + $amount,
            2,
        );
    }

    public function windowForLoan(Member $member, Loan $loan): LegacyLoanRepaymentWindow
    {
        return $this->buildDatabaseWindow($member, $loan);
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function resolveDatabaseWindow(
        Member $member,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
        ?LegacyLoanRepaymentInstallmentTracker $installmentTracker = null,
    ): ?LegacyLoanRepaymentWindow {
        $windows = $member->loans()
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->whereNotNull('disbursed_at')
            ->orderBy('disbursed_at')
            ->get()
            ->map(fn(Loan $loan): LegacyLoanRepaymentWindow => $this->buildDatabaseWindow($member, $loan));

        return LegacyLoanRepaymentWindow::firstOpenWindow(
            $windows,
            $paymentDate,
            $cumulativeRepaidByLoanKey,
            $installmentTracker,
        );
    }

    private function buildDatabaseWindow(Member $member, Loan $loan): LegacyLoanRepaymentWindow
    {
        $approved = (float) ($loan->amount_approved ?? $loan->amount);
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();
        $defaultGraceCycles = LegacyMigrationGraceCycleSettings::graceCycles();

        $graceCycles = (int) ($loan->grace_cycles ?? ($loan->has_grace_cycle ? 1 : $defaultGraceCycles));

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt, (int) $loan->id),
            disbursedAt: $disbursedAt,
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::totalRepaymentDue($approved),
            firstRepaymentAt: LegacyLoanRepaymentWindow::firstRepaymentAtForLoan(
                $loan,
                $defaultGraceCycles,
            ),
            loanId: $loan->id,
            graceCycles: $graceCycles,
            memberNumber: (string) $member->member_number,
            installmentsCount: (int) $loan->installments_count > 0 ? (int) $loan->installments_count : null,
        );
    }
}
