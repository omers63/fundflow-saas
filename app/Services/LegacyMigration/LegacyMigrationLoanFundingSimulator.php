<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Member;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationDateParser;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Estimates member fund available for legacy loan top-up at a historical disbursement date.
 *
 * During migration, loans are imported before payments are posted. For member-fund top-up,
 * we replay contribution rows from the payments CSV that fall before each disbursement.
 */
final class LegacyMigrationLoanFundingSimulator
{
    /**
     * @var array<int, list<array{paid_at: Carbon, amount: float}>>
     */
    private array $contributionsByMemberId = [];

    /**
     * @var array<int, list<array{disbursed_at: Carbon, member_portion: float}>>
     */
    private array $disbursementsByMemberId = [];

    public static function fromPaymentsCsv(string $absolutePath): self
    {
        $simulator = new self;

        foreach (AssociativeCsv::read($absolutePath) as $rowIndex => $row) {
            $type = strtolower(trim((string) ($row['payment_type'] ?? '')));

            if (in_array($type, ['loan_repayment', 'loan', 'repayment', 'ignore', 'skipped', 'skip'], true)) {
                continue;
            }

            $memberNumber = trim((string) ($row['member_number'] ?? ''));

            if ($memberNumber === '') {
                continue;
            }

            $memberId = Member::query()->where('member_number', $memberNumber)->value('id');

            if ($memberId === null) {
                continue;
            }

            $amountRaw = trim((string) ($row['amount'] ?? ''));

            if ($amountRaw === '' || ! is_numeric($amountRaw)) {
                continue;
            }

            $amount = round((float) $amountRaw, 2);

            if ($amount <= 0) {
                continue;
            }

            $dateRaw = trim((string) ($row['payment_date'] ?? ''));

            if ($dateRaw === '') {
                continue;
            }

            try {
                $paidAt = LegacyMigrationDateParser::parse($dateRaw, $rowIndex + 2, 'payment_date');
            } catch (\Throwable) {
                continue;
            }

            $simulator->contributionsByMemberId[(int) $memberId][] = [
                'paid_at' => $paidAt,
                'amount' => $amount,
            ];
        }

        foreach ($simulator->contributionsByMemberId as $memberId => $contributions) {
            usort(
                $contributions,
                fn (array $left, array $right): int => $left['paid_at']->timestamp <=> $right['paid_at']->timestamp,
            );

            $simulator->contributionsByMemberId[$memberId] = $contributions;
        }

        return $simulator;
    }

    public function fundBalanceBeforeDisbursement(Member $member, CarbonInterface $disbursedAt): float
    {
        $memberId = (int) $member->id;
        $cutoff = Carbon::parse($disbursedAt)->startOfDay();

        $balance = 0.0;

        foreach ($this->contributionsByMemberId[$memberId] ?? [] as $contribution) {
            if ($contribution['paid_at']->lt($cutoff)) {
                $balance = round($balance + $contribution['amount'], 2);
            }
        }

        foreach ($this->disbursementsByMemberId[$memberId] ?? [] as $disbursement) {
            if ($disbursement['disbursed_at']->lt($cutoff)) {
                $balance = round($balance - $disbursement['member_portion'], 2);
            }
        }

        return max(0.0, $balance);
    }

    public function recordDisbursement(Member $member, CarbonInterface $disbursedAt, float $memberPortion): void
    {
        if ($memberPortion <= 0.00001) {
            return;
        }

        $memberId = (int) $member->id;

        $this->disbursementsByMemberId[$memberId][] = [
            'disbursed_at' => Carbon::parse($disbursedAt)->startOfDay(),
            'member_portion' => round($memberPortion, 2),
        ];
    }
}
