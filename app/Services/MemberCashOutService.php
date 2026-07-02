<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\CashOutRequestAcceptedNotification;
use App\Notifications\Tenant\CashOutRequestRejectedNotification;
use App\Notifications\Tenant\NewCashOutRequestNotification;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MemberCashOutService
{
    private static int $notificationSuppressionDepth = 0;

    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
        private OperationalReviewWorkflowService $reviewWorkflow,
        private SyntheticBankStatementFactory $syntheticStatements,
        private BankClearanceLinkageResolver $clearanceLinkageResolver,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutNotifications(callable $callback): mixed
    {
        self::$notificationSuppressionDepth++;

        try {
            return $callback();
        } finally {
            self::$notificationSuppressionDepth--;
        }
    }

    public static function notificationsSuppressed(): bool
    {
        return self::$notificationSuppressionDepth > 0;
    }

    /**
     * Legacy migration: request and approve a cash-out for the full imported disbursement amount.
     */
    public function submitAndAcceptImportedLoanDisbursement(
        Member $member,
        int $loanId,
        float $amount,
        CarbonInterface $disbursedAt,
        ?int $reviewedBy = null,
    ): CashOutRequest {
        return self::withoutNotifications(function () use ($member, $loanId, $amount, $disbursedAt, $reviewedBy): CashOutRequest {
            $request = $this->submit(
                $member->fresh(),
                $amount,
                __('Legacy migration cash-out for loan #:id disbursement', ['id' => $loanId]),
                bypassAvailabilityGuard: true,
            );

            $this->accept(
                $request->fresh(),
                $reviewedBy,
                __('Legacy migration auto-approved'),
                $disbursedAt,
                bypassAvailabilityGuard: true,
            );

            return $request->fresh();
        });
    }

    public function availableCashForWithdrawal(Member $member, ?CashOutRequest $excludeRequest = null): float
    {
        $balance = max(0.0, $this->cashBalanceFor($member));
        $reserved = $this->reservedForNextEmi($member);
        $pending = $this->pendingCashOutAmount($member, $excludeRequest);

        return max(0.0, round($balance - $reserved - $pending, 2));
    }

    private function cashBalanceFor(Member $member): float
    {
        return (float) ($member->cashAccount?->balance ?? 0);
    }

    private function pendingCashOutAmount(Member $member, ?CashOutRequest $excludeRequest = null): float
    {
        $pendingQuery = CashOutRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending');

        if ($excludeRequest !== null && $excludeRequest->exists) {
            $pendingQuery->whereKeyNot($excludeRequest->getKey());
        }

        return (float) $pendingQuery->sum('amount');
    }

    public function reservedForNextEmi(Member $member): float
    {
        $installment = LoanInstallment::query()
            ->whereHas('loan', fn ($query) => $query
                ->where('member_id', $member->id)
                ->where('status', 'active'))
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('installment_number')
            ->first();

        if ($installment === null) {
            return 0.0;
        }

        return round((float) $installment->amount + (float) ($installment->late_fee_amount ?? 0), 2);
    }

    public function submit(Member $member, float $amount, ?string $notes = null, bool $bypassAvailabilityGuard = false): CashOutRequest
    {
        if (! $bypassAvailabilityGuard && ! app(MemberMembershipPolicy::class)->canRequestCashOut($member)) {
            throw new InvalidArgumentException(__('Cash-out is not available for this membership status.'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a withdrawal amount greater than zero.'));
        }

        $available = $bypassAvailabilityGuard
            ? max(0.0, round($this->cashBalanceFor($member), 2))
            : $this->availableCashForWithdrawal($member);

        $this->assertAmountWithinAvailable(
            $amount,
            $available,
            $bypassAvailabilityGuard
            ? __('Insufficient member cash balance for this withdrawal (:available).', [
                'available' => number_format($available, 2),
            ])
            : __('Amount exceeds available cash (:available).', [
                'available' => number_format($available, 2),
            ]),
        );

        return DB::transaction(function () use ($member, $amount, $notes): CashOutRequest {
            $request = CashOutRequest::create([
                'member_id' => $member->id,
                'amount' => $amount,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            $this->notifyAdminsOfNewRequest($request);

            return $request;
        });
    }

    /**
     * Move a non-active member's fund balance to cash and accept a cash-out for that amount on the given date.
     */
    public function submitFundBalanceCashOut(
        Member $member,
        ?string $notes = null,
        ?CarbonInterface $cashOutAt = null,
        ?int $reviewedBy = null,
    ): CashOutRequest {
        if ($member->status === 'active') {
            throw new InvalidArgumentException(__('Fund cash-out is only available for non-active members.'));
        }

        if (! app(MemberMembershipPolicy::class)->canReceivePayout($member)) {
            throw new InvalidArgumentException(__('Payout is held for admin review.'));
        }

        $cashOutAt = $cashOutAt ?? BusinessDay::now();
        $description = __('Fund balance cash-out');
        $note = filled($notes) ? trim((string) $notes) : $description;

        return DB::transaction(function () use ($member, $note, $description, $cashOutAt, $reviewedBy): CashOutRequest {
            $amount = AccountingService::withoutMemberCashCollection(
                fn (): float => app(MemberFundCashTransferService::class)->transferPositiveFundBalanceToCash(
                    $member->fresh(),
                    $member,
                    $description,
                    $cashOutAt,
                ),
            );

            if ($amount <= 0.00001) {
                throw new InvalidArgumentException(__('Member fund account has no balance to cash out.'));
            }

            $request = $this->submit(
                $member->fresh(),
                $amount,
                $note,
                bypassAvailabilityGuard: true,
            );

            $this->accept(
                $request->fresh(),
                $reviewedBy,
                null,
                $cashOutAt,
                bypassAvailabilityGuard: true,
            );

            return $request->fresh();
        });
    }

    public function accept(
        CashOutRequest $request,
        ?int $reviewedBy = null,
        ?string $remarks = null,
        ?CarbonInterface $transactedAt = null,
        bool $bypassAvailabilityGuard = false,
    ): void {
        $this->assertPendingRequest($request, __('Only pending cash-out requests can be accepted.'));

        $request->loadMissing('member');
        $member = $request->member;
        $member->loadMissing('cashAccount');
        $memberCash = $member->cashAccount;
        $amount = (float) $request->amount;

        if ($memberCash === null || Account::masterCash() === null) {
            throw new RuntimeException(__('Required cash accounts are not configured.'));
        }

        $available = $bypassAvailabilityGuard
            ? max(0.0, round($this->cashBalanceFor($member), 2))
            : $this->availableCashForWithdrawal($member, $request);

        $this->assertAmountWithinAvailable(
            $amount,
            $available,
            $bypassAvailabilityGuard
            ? __('Insufficient member cash balance for this withdrawal (:available).', [
                'available' => number_format($available, 2),
            ])
            : __('Member no longer has enough available cash for this request.'),
        );

        DB::transaction(function () use ($request, $member, $memberCash, $amount, $reviewedBy, $remarks, $transactedAt): void {
            $reviewedAt = $transactedAt ?? BusinessDay::now();
            $description = __('Cash out #:id – :name', [
                'id' => $request->id,
                'name' => $member->name,
            ]);

            $this->accounting->debitMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description.' '.__('(cash out)'),
                __('(cash out mirror)'),
                $request,
                $reviewedAt,
                $member->id,
            );

            $statement = $this->syntheticStatements->memberCashOuts();
            $bankTxn = $this->createCashOutBankTransaction(
                $statement,
                $request,
                $member,
                $description,
                $amount,
                $reviewedAt,
            );

            $this->reviewWorkflow->markReviewed($request, 'accepted', $reviewedBy, $remarks, $reviewedAt, [
                'bank_transaction_id' => $bankTxn->id,
            ]);

            $this->notifyMemberAboutCashOut($request, true);
        });
    }

    /**
     * Clear an uncleared cash-out bank transaction by matching it to an imported statement line.
     */
    public function clearTransaction(BankTransaction $uncleared, BankTransaction $imported): void
    {
        $this->bankClearance->clearMatchedPair(
            $uncleared,
            $imported,
            $this->clearanceLinkageResolver->forCashOut($uncleared),
        );
    }

    public function reject(CashOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        $this->assertPendingRequest($request, __('Only pending cash-out requests can be rejected.'));
        $this->assertRemarksProvided($remarks, __('Provide a reason for rejection.'));

        DB::transaction(function () use ($request, $reviewedBy, $remarks): void {
            $this->reviewWorkflow->markReviewed($request, 'rejected', $reviewedBy, $remarks, BusinessDay::now());

            $this->notifyMemberAboutCashOut($request, false);
        });
    }

    private function assertPendingRequest(CashOutRequest $request, string $message): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException($message);
        }
    }

    private function createCashOutBankTransaction(
        BankStatement $statement,
        CashOutRequest $request,
        Member $member,
        string $description,
        float $amount,
        CarbonInterface $reviewedAt,
    ): BankTransaction {
        return BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => $reviewedAt->toDateString(),
            'description' => $description,
            'amount' => -$amount,
            'reference' => (string) $request->id,
            'status' => 'imported',
            'member_id' => $member->id,
            'hash' => md5("cash-out-{$request->id}-{$amount}"),
            'is_cleared' => false,
            'cash_out_request_id' => $request->id,
        ]);
    }

    private function notifyMemberAboutCashOut(CashOutRequest $request, bool $accepted): void
    {
        if (self::notificationsSuppressed()) {
            return;
        }

        $request->loadMissing('member.user');

        $notification = $accepted
            ? new CashOutRequestAcceptedNotification($request)
            : new CashOutRequestRejectedNotification($request);

        $request->member->user?->notify($notification);
    }

    private function notifyAdminsOfNewRequest(CashOutRequest $request): void
    {
        if (self::notificationsSuppressed()) {
            return;
        }

        $this->reviewWorkflow->notifyAdmins(new NewCashOutRequestNotification($request));
    }

    private function assertAmountWithinAvailable(float $amount, float $available, string $message): void
    {
        if ($amount > $available + 0.01) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertRemarksProvided(?string $remarks, string $message): void
    {
        if ($remarks === null || trim($remarks) === '') {
            throw new InvalidArgumentException($message);
        }
    }
}
