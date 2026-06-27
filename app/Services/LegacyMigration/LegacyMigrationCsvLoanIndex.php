<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Support\AssociativeCsv;
use App\Support\LegacyLoanCsvIdentity;
use App\Support\LegacyMigrationDateParser;
use App\Support\LegacyMigrationGraceCycleSettings;
use Carbon\Carbon;

final class LegacyMigrationCsvLoanIndex
{
    /**
     * @var array<string, list<array{
     *     disbursed_at: Carbon,
     *     amount_approved: float,
     *     legacy_loan_id: int|null,
     *     installments_count: int|null,
     *     master_portion: float|null,
     *     settlement_threshold: float|null,
     * }>>
     */
    private array $loansByMemberNumber = [];

    private function __construct(
        private readonly int $graceCycles,
    ) {}

    public static function fromPath(string $absolutePath, ?int $graceCycles = null): self
    {
        $index = new self(
            max(0, min(2, $graceCycles ?? LegacyMigrationGraceCycleSettings::graceCycles())),
        );

        foreach (AssociativeCsv::read($absolutePath) as $rowIndex => $row) {
            $status = strtolower(trim((string) ($row['loan_status'] ?? 'active')));

            if (in_array($status, ['cancelled', 'rejected'], true)) {
                continue;
            }

            $memberNumber = trim((string) ($row['member_number'] ?? ''));

            if ($memberNumber === '') {
                continue;
            }

            $approvedRaw = trim((string) ($row['amount_approved'] ?? ''));

            if ($approvedRaw === '' || ! is_numeric($approvedRaw)) {
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

            $installmentsCount = null;
            $installmentsCell = trim((string) ($row['installments_count'] ?? ''));

            if ($installmentsCell !== '' && ctype_digit($installmentsCell)) {
                $installmentsCount = max(1, (int) $installmentsCell);
            }

            $masterPortion = null;
            $masterCell = trim((string) ($row['master_portion'] ?? ''));

            if ($masterCell !== '' && is_numeric($masterCell)) {
                $masterPortion = (float) $masterCell;
            }

            $settlementThreshold = null;
            $settlementCell = trim((string) ($row['settlement_threshold'] ?? ''));

            if ($settlementCell !== '' && is_numeric($settlementCell)) {
                $settlementThreshold = (float) $settlementCell;
            }

            $index->loansByMemberNumber[$memberNumber][] = [
                'disbursed_at' => $disbursedAt,
                'amount_approved' => (float) $approvedRaw,
                'legacy_loan_id' => LegacyLoanCsvIdentity::legacyLoanIdFromRow($row),
                'installments_count' => $installmentsCount,
                'master_portion' => $masterPortion,
                'settlement_threshold' => $settlementThreshold,
            ];
        }

        foreach ($index->loansByMemberNumber as $memberNumber => $loans) {
            usort(
                $loans,
                fn (array $left, array $right): int => $left['disbursed_at']->timestamp <=> $right['disbursed_at']->timestamp,
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
        ?LegacyLoanRepaymentInstallmentTracker $installmentTracker = null,
    ): ?LegacyLoanRepaymentWindow {
        $windows = array_map(
            fn (array $loan): LegacyLoanRepaymentWindow => $this->buildWindow(trim($memberNumber), $loan),
            $this->loansByMemberNumber[trim($memberNumber)] ?? [],
        );

        return LegacyLoanRepaymentWindow::firstOpenWindow(
            $windows,
            $paymentDate,
            $cumulativeRepaidByLoanKey,
            $installmentTracker,
        );
    }

    public function isEmpty(): bool
    {
        return $this->loansByMemberNumber === [];
    }

    public function hasMember(string $memberNumber): bool
    {
        return isset($this->loansByMemberNumber[trim($memberNumber)]);
    }

    /**
     * @return list<LegacyMemberLoanWindow>
     */
    public function loanWindowsForMember(string $memberNumber): array
    {
        $memberNumber = trim($memberNumber);

        return array_map(
            fn (array $loan): LegacyMemberLoanWindow => LegacyMemberLoanWindow::fromCsvLoan($memberNumber, $loan),
            $this->loansByMemberNumber[$memberNumber] ?? [],
        );
    }

    /**
     * @param  array{disbursed_at: Carbon, amount_approved: float, legacy_loan_id: int|null, installments_count: int|null, master_portion: float|null, settlement_threshold: float|null}  $loan
     */
    private function buildWindow(string $memberNumber, array $loan): LegacyLoanRepaymentWindow
    {
        $approved = $loan['amount_approved'];
        $legacyLoanId = $loan['legacy_loan_id'] ?? null;

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey($memberNumber, $loan['disbursed_at'], $legacyLoanId),
            disbursedAt: $loan['disbursed_at'],
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::fromLoansCsvRow([
                'master_portion' => $loan['master_portion'] !== null ? (string) $loan['master_portion'] : '',
                'settlement_threshold' => $loan['settlement_threshold'] !== null ? (string) $loan['settlement_threshold'] : '',
            ], $approved),
            firstRepaymentAt: LegacyLoanRepaymentWindow::firstRepaymentAtForDisbursement(
                $loan['disbursed_at'],
                $this->graceCycles,
            ),
            loanId: $legacyLoanId,
            graceCycles: $this->graceCycles,
            memberNumber: $memberNumber,
            installmentsCount: $loan['installments_count'],
        );
    }
}
