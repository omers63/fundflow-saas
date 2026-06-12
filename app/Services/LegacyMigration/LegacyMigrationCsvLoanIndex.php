<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationDateParser;
use Carbon\Carbon;

final class LegacyMigrationCsvLoanIndex
{
    /**
     * @var array<string, list<array{
     *     disbursed_at: Carbon,
     *     amount_approved: float
     * }>>
     */
    private array $loansByMemberNumber = [];

    public static function fromPath(string $absolutePath): self
    {
        $index = new self;

        foreach (AssociativeCsv::read($absolutePath) as $rowIndex => $row) {
            $status = strtolower(trim((string) ($row['loan_status'] ?? 'active')));

            if (in_array($status, ['completed', 'early_settled', 'cancelled', 'rejected'], true)) {
                continue;
            }

            $memberNumber = trim((string) ($row['member_number'] ?? ''));

            if ($memberNumber === '') {
                continue;
            }

            $approvedRaw = trim((string) ($row['amount_approved'] ?? ''));

            if ($approvedRaw === '' || !is_numeric($approvedRaw)) {
                continue;
            }

            $disbursedRaw = trim((string) ($row['disbursed_at'] ?? ''));

            if ($disbursedRaw === '') {
                continue;
            }

            try {
                $disbursedAt = LegacyMigrationDateParser::parse($disbursedRaw, $rowIndex + 2, 'disbursed_at');
            } catch (\Throwable) {
                continue;
            }

            $index->loansByMemberNumber[$memberNumber][] = [
                'disbursed_at' => $disbursedAt,
                'amount_approved' => (float) $approvedRaw,
            ];
        }

        foreach ($index->loansByMemberNumber as $memberNumber => $loans) {
            usort(
                $loans,
                fn(array $left, array $right): int => $left['disbursed_at']->timestamp <=> $right['disbursed_at']->timestamp,
            );

            $index->loansByMemberNumber[$memberNumber] = $loans;
        }

        return $index;
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public function repaymentWindowAt(
        string $memberNumber,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
    ): ?LegacyLoanRepaymentWindow {
        foreach ($this->loansByMemberNumber[trim($memberNumber)] ?? [] as $loan) {
            $window = $this->buildWindow(trim($memberNumber), $loan);

            if (!$window->isDisbursedOnOrBefore($paymentDate)) {
                continue;
            }

            $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

            if ($window->hasRemainingRepayment($cumulative)) {
                return $window;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->loansByMemberNumber === [];
    }

    /**
     * @param  array{disbursed_at: Carbon, amount_approved: float}  $loan
     */
    private function buildWindow(string $memberNumber, array $loan): LegacyLoanRepaymentWindow
    {
        $approved = $loan['amount_approved'];

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey($memberNumber, $loan['disbursed_at']),
            disbursedAt: $loan['disbursed_at'],
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::totalRepaymentDue($approved),
        );
    }
}
