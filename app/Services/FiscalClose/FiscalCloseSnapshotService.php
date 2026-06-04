<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FiscalCloseSnapshotService
{
    /**
     * @return array<string, float|int>
     */
    public function capturePoolTotals(): array
    {
        $masterCash = (float) (Account::masterCash()?->balance ?? 0);
        $masterFund = (float) (Account::masterFund()?->balance ?? 0);
        $masterFees = (float) (Account::masterFees()?->balance ?? 0);
        $masterBank = (float) (Account::query()->where('type', 'bank')->where('is_master', true)->value('balance') ?? 0);
        $memberCashSum = (float) Account::query()->where('is_master', false)->where('type', 'cash')->sum('balance');
        $memberFundSum = (float) Account::query()->where('is_master', false)->where('type', 'fund')->sum('balance');

        return [
            'master_cash' => $masterCash,
            'master_fund' => $masterFund,
            'master_fees' => $masterFees,
            'master_bank' => $masterBank,
            'member_cash_sum' => $memberCashSum,
            'member_fund_sum' => $memberFundSum,
            'captured_at' => BusinessDay::now()->toIso8601String(),
        ];
    }

    public function build(FiscalClose $close): FiscalClose
    {
        $periodEnd = $close->period_end->copy()->endOfDay();
        $snapshots = collect();

        Member::query()
            ->with(['cashAccount', 'fundAccount', 'loans.installments'])
            ->orderBy('id')
            ->chunkById(100, function ($members) use ($close, $periodEnd, &$snapshots): void {
                foreach ($members as $member) {
                    $snapshots->push($this->captureMemberSnapshot($close, $member, $periodEnd));
                }
            });

        $openArrearsCount = Contribution::query()
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->whereDate('period', '<=', $periodEnd->toDateString())
            ->count();

        $activeLoanCount = Loan::query()->active()->count();

        $close->update([
            'status' => FiscalClose::STATUS_PENDING_APPROVAL,
            'pool_snapshot_json' => $this->capturePoolTotals(),
            'member_count' => $snapshots->count(),
            'active_loan_count' => $activeLoanCount,
            'open_arrears_period_count' => $openArrearsCount,
            'checksum' => $this->computeChecksum($snapshots),
        ]);

        return $close->fresh(['memberSnapshots']);
    }

    public function captureMemberSnapshot(
        FiscalClose $close,
        Member $member,
        Carbon $periodEnd,
    ): FiscalCloseMemberSnapshot {
        $contributionArrears = Contribution::query()
            ->where('member_id', $member->id)
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->whereDate('period', '<=', $periodEnd->toDateString())
            ->orderBy('period')
            ->get()
            ->map(fn (Contribution $contribution): array => [
                'contribution_id' => $contribution->id,
                'period' => $contribution->period?->toDateString(),
                'amount_due' => (float) ($contribution->amount_due ?? $contribution->amount),
                'amount_collected' => (float) ($contribution->amount_collected ?? 0),
                'late_fee_amount' => (float) ($contribution->late_fee_amount ?? 0),
                'collection_status' => $contribution->collection_status,
            ])
            ->values()
            ->all();

        $loansJson = $member->loans()
            ->whereIn('status', ['active', 'partially_disbursed', 'transferred'])
            ->with(['installments' => fn ($query) => $query->whereIn('status', ['pending', 'overdue'])->orderBy('due_date')])
            ->get()
            ->map(fn (Loan $loan): array => [
                'loan_id' => $loan->id,
                'status' => $loan->status,
                'outstanding' => (float) $loan->remaining_amount,
                'amount_disbursed' => (float) $loan->amount_disbursed,
                'total_repaid' => (float) $loan->total_repaid,
                'overdue_installments' => $loan->installments->map(fn ($installment): array => [
                    'id' => $installment->id,
                    'due_date' => $installment->due_date?->toDateString(),
                    'amount' => (float) $installment->amount,
                    'status' => $installment->status,
                ])->values()->all(),
                'next_due' => $loan->installments->first()?->due_date?->toDateString(),
            ])
            ->values()
            ->all();

        return FiscalCloseMemberSnapshot::query()->updateOrCreate(
            [
                'fiscal_close_id' => $close->id,
                'member_id' => $member->id,
            ],
            [
                'cash_balance' => $member->getCashBalance(),
                'fund_balance' => $member->getFundBalance(),
                'opening_cash_before' => (float) ($member->opening_cash_balance ?? 0),
                'opening_fund_before' => (float) ($member->opening_fund_balance ?? 0),
                'contribution_arrears_json' => $contributionArrears,
                'loans_json' => $loansJson,
                'delinquency_json' => [
                    'status' => $member->status,
                ],
                'eligibility_json' => null,
            ],
        );
    }

    /**
     * @param  Collection<int, FiscalCloseMemberSnapshot>  $snapshots
     */
    public function computeChecksum(Collection $snapshots): string
    {
        $payload = $snapshots
            ->sortBy('member_id')
            ->map(fn (FiscalCloseMemberSnapshot $snapshot): array => [
                'member_id' => $snapshot->member_id,
                'cash_balance' => (string) $snapshot->cash_balance,
                'fund_balance' => (string) $snapshot->fund_balance,
                'contribution_arrears_json' => $snapshot->contribution_arrears_json,
                'loans_json' => $snapshot->loans_json,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
