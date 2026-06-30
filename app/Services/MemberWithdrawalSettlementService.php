<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanEarlySettlementService;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Membership withdrawal settlement rules:
 *
 * 1. Block when the member has pipeline loans (pending / approved / partially disbursed).
 * 2. Block when the member is an active unreleased guarantor on another member's loan.
 * 3. Early-settle every active loan owned by the member (principal + late fees from cash).
 * 4. Fund shortfall for settlement: transfer positive fund balance to cash before settling.
 * 5. After settlement, transfer remaining positive fund to cash and submit a pending cash-out
 *    for the full cash balance (unless payout is held for admin review).
 */
final class MemberWithdrawalSettlementService
{
    /** @var list<string> */
    private const PIPELINE_LOAN_STATUSES = ['pending', 'approved', 'partially_disbursed'];

    public function __construct(
        private readonly LoanEarlySettlementService $earlySettlement,
        private readonly MemberFundCashTransferService $fundCashTransfer,
        private readonly MemberCashOutService $cashOuts,
        private readonly AccountingService $accounting,
    ) {}

    /**
     * @return array{
     *     can_withdraw: bool,
     *     blockers: list<string>,
     *     pipeline_loan_count: int,
     *     guarantor_obligation_count: int,
     *     active_loan_count: int,
     *     settlement_required_cash: float,
     *     member_cash_balance: float,
     *     member_fund_balance: float,
     *     projected_cash_out: float,
     * }
     */
    public function assess(Member $member): array
    {
        $member->unsetRelation('accounts');
        $member->loadMissing(['cashAccount', 'fundAccount']);

        $blockers = $this->blockers($member);
        $activeLoans = $this->activeLoans($member);
        $settlementRequired = round($activeLoans->sum(
            fn (Loan $loan): float => $this->earlySettlement->requiredCash($loan),
        ), 2);

        $cash = round($member->getCashBalance(), 2);
        $fund = round($member->getFundBalance(), 2);
        $transferableFund = round(max(0.0, $fund), 2);
        $projectedCash = round(max(0.0, $cash + $transferableFund - max(0.0, $settlementRequired - $cash)), 2);

        if ($settlementRequired > $cash + $transferableFund + 0.00001) {
            $blockers[] = __('Insufficient cash and fund to early-settle active loans. Required: :required, available: :available.', [
                'required' => number_format($settlementRequired, 2),
                'available' => number_format($cash + $transferableFund, 2),
            ]);
        }

        return [
            'can_withdraw' => $blockers === [],
            'blockers' => $blockers,
            'pipeline_loan_count' => $this->pipelineLoans($member)->count(),
            'guarantor_obligation_count' => $this->guarantorObligations($member)->count(),
            'active_loan_count' => $activeLoans->count(),
            'settlement_required_cash' => $settlementRequired,
            'member_cash_balance' => $cash,
            'member_fund_balance' => $fund,
            'projected_cash_out' => $projectedCash,
        ];
    }

    public function assertWithdrawable(Member $member): void
    {
        $assessment = $this->assess($member);

        if ($assessment['can_withdraw']) {
            return;
        }

        throw new InvalidArgumentException(implode(' ', $assessment['blockers']));
    }

    public function executeSettlement(
        Member $member,
        string $reason,
        bool $holdPayout = false,
        ?CarbonInterface $withdrawAt = null,
    ): ?CashOutRequest {
        $this->assertWithdrawable($member);

        $this->accounting->createMemberAccounts($member);
        $member->load(['cashAccount', 'fundAccount']);

        $at = $withdrawAt ?? BusinessDay::now();

        return DB::transaction(function () use ($member, $reason, $holdPayout, $at): ?CashOutRequest {
            $this->fundActiveLoansForSettlement($member, $at);
            $this->earlySettleActiveLoans($member, $at);

            if ($holdPayout) {
                return null;
            }

            return $this->submitRemainingCashOut($member, $reason, $at);
        });
    }

    /**
     * @return list<string>
     */
    private function blockers(Member $member): array
    {
        $blockers = [];

        if ($member->status === 'withdrawn') {
            $blockers[] = __('Member has already withdrawn.');
        }

        $pipeline = $this->pipelineLoans($member);

        if ($pipeline->isNotEmpty()) {
            $blockers[] = trans_choice(
                'Resolve :count open loan application before withdrawal.|Resolve :count open loan applications before withdrawal.',
                $pipeline->count(),
                ['count' => $pipeline->count()],
            );
        }

        $guarantorLoans = $this->guarantorObligations($member);

        if ($guarantorLoans->isNotEmpty()) {
            $blockers[] = trans_choice(
                'Release or transfer guarantor liability on :count active loan before withdrawal.|Release or transfer guarantor liability on :count active loans before withdrawal.',
                $guarantorLoans->count(),
                ['count' => $guarantorLoans->count()],
            );
        }

        return $blockers;
    }

    /**
     * @return Collection<int, Loan>
     */
    private function pipelineLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', self::PIPELINE_LOAN_STATUSES)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    private function guarantorObligations(Member $member): Collection
    {
        return Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->where('status', 'active')
            ->whereNull('guarantor_released_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Loan>
     */
    private function activeLoans(Member $member): Collection
    {
        return Loan::query()
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->with('installments')
            ->orderBy('id')
            ->get();
    }

    private function fundActiveLoansForSettlement(Member $member, CarbonInterface $at): void
    {
        $required = round($this->activeLoans($member)->sum(
            fn (Loan $loan): float => $this->earlySettlement->requiredCash($loan),
        ), 2);

        if ($required <= 0.00001) {
            return;
        }

        $member->unsetRelation('accounts');
        $cash = $member->getCashBalance();

        if ($cash >= $required - 0.00001) {
            return;
        }

        $shortfall = $required - $cash;
        $transferable = round(max(0.0, $member->getFundBalance()), 2);
        $transferAmount = round(min($shortfall, $transferable), 2);

        if ($transferAmount <= 0.00001) {
            throw new RuntimeException(__('Insufficient cash and fund to early-settle active loans.'));
        }

        $this->fundCashTransfer->transferAmount(
            $member,
            $transferAmount,
            $member,
            __('Membership withdrawal — fund to cash for loan settlement'),
            $at,
        );
    }

    private function earlySettleActiveLoans(Member $member, CarbonInterface $at): void
    {
        foreach ($this->activeLoans($member->fresh()) as $loan) {
            $this->earlySettlement->earlySettle(
                $loan->fresh(['member', 'installments']),
                sendNotification: false,
                transactedAt: $at,
            );
        }
    }

    private function submitRemainingCashOut(Member $member, string $reason, CarbonInterface $at): ?CashOutRequest
    {
        $member = $member->fresh();
        $member->unsetRelation('accounts');
        $member->load(['cashAccount', 'fundAccount']);

        $this->fundCashTransfer->transferPositiveFundBalanceToCash(
            $member,
            $member,
            __('Membership withdrawal — fund to cash'),
            $at,
        );

        $member = $member->fresh();
        $member->unsetRelation('accounts');
        $cashOutAmount = round(max(0.0, $member->getCashBalance()), 2);

        if ($cashOutAmount <= 0.00001) {
            return null;
        }

        $note = trim($reason) !== ''
            ? __('Auto cash-out from membership withdrawal: :reason', ['reason' => trim($reason)])
            : __('Auto cash-out from membership withdrawal');

        return MemberCashOutService::withoutNotifications(
            fn (): CashOutRequest => $this->cashOuts->submit(
                $member,
                $cashOutAmount,
                $note,
                bypassAvailabilityGuard: true,
            ),
        );
    }
}
