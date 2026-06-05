<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContributionArrearsClearanceService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly LoanDelinquencyService $delinquency,
    ) {}

    /**
     * Waive a contribution arrear period without debiting member cash.
     *
     * @return 'cleared'|'skipped'|'already_clear'
     */
    public function clearMemberPeriod(Member $member, int $month, int $year, ?string $note = null): string
    {
        if ((float) $member->monthly_contribution_amount <= 0) {
            throw new InvalidArgumentException(__('This member has no monthly contribution amount.'));
        }

        if ($member->isExemptFromContributions($month, $year)) {
            throw new InvalidArgumentException(__('This member is exempt from contributions for this period.'));
        }

        $existing = Contribution::findForMemberPeriod((int) $member->id, $month, $year);

        if ($existing?->status === 'posted') {
            return 'already_clear';
        }

        if ($existing?->status === 'waived') {
            return 'already_clear';
        }

        $waivedNote = trim(($note ?? '').' '.__('Arrears cleared by administrator (no cash movement).'));

        DB::transaction(function () use ($member, $month, $year, $existing, $waivedNote): void {
            if ($existing !== null) {
                $this->waiveExistingContribution($existing, $waivedNote);

                return;
            }

            Contribution::query()->create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => 0,
                'status' => 'waived',
                'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
                'notes' => $waivedNote,
                'is_late' => false,
                'late_fee_amount' => null,
                'late_fee_tier' => null,
                'overdue_since' => null,
            ]);
        });

        $this->delinquency->syncMemberDelinquencyStatusForMember($member->fresh() ?? $member);

        return 'cleared';
    }

    private function waiveExistingContribution(Contribution $contribution, string $note): void
    {
        $lateFeeCollected = $this->accounting->contributionLateFeeCollectedAmount($contribution);

        if ($lateFeeCollected > 0.00001) {
            $this->accounting->reverseContributionLateFee($contribution, $lateFeeCollected);
        }

        $contribution->transactions()->delete();

        $contribution->update([
            'status' => 'waived',
            'amount_collected' => 0,
            'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
            'notes' => trim($note),
            'is_late' => false,
            'late_fee_amount' => null,
            'late_fee_tier' => null,
            'overdue_since' => null,
            'posted_at' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $record  Arrears table row
     * @return 'cleared'|'skipped'|'already_clear'
     */
    public function clearArrearsRecord(array $record, ?string $note = null): string
    {
        $member = Member::query()->find((int) ($record['member_id'] ?? 0));

        if ($member === null) {
            return 'skipped';
        }

        return $this->clearMemberPeriod(
            $member,
            (int) $record['month'],
            (int) $record['year'],
            $note,
        );
    }

    /**
     * @param  iterable<array<string, mixed>>  $records
     * @return array{cleared: int, skipped: int, already_clear: int}
     */
    public function clearManyRecords(iterable $records, ?string $note = null): array
    {
        $summary = [
            'cleared' => 0,
            'skipped' => 0,
            'already_clear' => 0,
        ];

        foreach ($records as $record) {
            $row = is_array($record) ? $record : (array) $record;

            try {
                $outcome = $this->clearArrearsRecord($row, $note);
            } catch (InvalidArgumentException) {
                $summary['skipped']++;

                continue;
            }

            $summary[$outcome === 'cleared' ? 'cleared' : ($outcome === 'already_clear' ? 'already_clear' : 'skipped')]++;
        }

        return $summary;
    }
}
