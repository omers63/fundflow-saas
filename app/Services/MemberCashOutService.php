<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\CashOutRequestAcceptedNotification;
use App\Notifications\Tenant\CashOutRequestRejectedNotification;
use App\Notifications\Tenant\NewCashOutRequestNotification;
use App\Support\BankStatementBuckets;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MemberCashOutService
{
    public function __construct(
        private AccountingService $accounting,
        private BankTransactionClearanceService $bankClearance,
    ) {
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
            ->whereHas('loan', fn($query) => $query
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

    public function submit(Member $member, float $amount, ?string $notes = null): CashOutRequest
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a withdrawal amount greater than zero.'));
        }

        $available = $this->availableCashForWithdrawal($member);
        $this->assertAmountWithinAvailable(
            $amount,
            $available,
            __('Amount exceeds available cash (:available).', [
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

    public function accept(CashOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        $this->assertPendingRequest($request, __('Only pending cash-out requests can be accepted.'));

        $request->loadMissing('member');
        $member = $request->member;
        $member->loadMissing('cashAccount');
        $memberCash = $member->cashAccount;
        $amount = (float) $request->amount;

        if ($memberCash === null || Account::masterCash() === null) {
            throw new RuntimeException(__('Required cash accounts are not configured.'));
        }

        $this->assertAmountWithinAvailable(
            $amount,
            $this->availableCashForWithdrawal($member, $request),
            __('Member no longer has enough available cash for this request.'),
        );

        DB::transaction(function () use ($request, $member, $memberCash, $amount, $reviewedBy, $remarks): void {
            $reviewedAt = now();
            $description = __('Cash out #:id – :name', [
                'id' => $request->id,
                'name' => $member->name,
            ]);

            $this->accounting->debitMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description . ' ' . __('(cash out)'),
                __('(cash out mirror)'),
                $request,
                null,
                $member->id,
            );

            $statement = $this->memberCashOutStatement();
            $bankTxn = $this->createCashOutBankTransaction(
                $statement,
                $request,
                $member,
                $description,
                $amount,
                $reviewedAt,
            );

            $this->markRequestReviewed($request, 'accepted', $reviewedBy, $remarks, $reviewedAt, [
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
        $this->bankClearance->clearMatchedPair($uncleared, $imported, [
            'cash_out_request_id' => $uncleared->cash_out_request_id,
            'status' => 'posted',
            'member_id' => $uncleared->member_id,
        ]);
    }

    public function reject(CashOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        $this->assertPendingRequest($request, __('Only pending cash-out requests can be rejected.'));
        $this->assertRemarksProvided($remarks, __('Provide a reason for rejection.'));

        DB::transaction(function () use ($request, $reviewedBy, $remarks): void {
            $this->markRequestReviewed($request, 'rejected', $reviewedBy, $remarks, now());

            $this->notifyMemberAboutCashOut($request, false);
        });
    }

    private function assertPendingRequest(CashOutRequest $request, string $message): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException($message);
        }
    }

    private function memberCashOutStatement(): BankStatement
    {
        return BankStatement::firstOrCreate(
            ['filename' => BankStatementBuckets::MEMBER_CASH_OUTS, 'status' => 'completed'],
            [
                'bank_name' => __('Member cash outs'),
                'total_rows' => 0,
                'imported_rows' => 0,
                'duplicate_rows' => 0,
            ],
        );
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

    /**
     * @param  array<string, mixed>  $extraUpdates
     */
    private function markRequestReviewed(
        CashOutRequest $request,
        string $status,
        ?int $reviewedBy = null,
        ?string $remarks = null,
        ?CarbonInterface $reviewedAt = null,
        array $extraUpdates = [],
    ): void {
        $request->update(array_merge([
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt ?? now(),
            'admin_remarks' => $remarks,
        ], $extraUpdates));
    }

    private function notifyMemberAboutCashOut(CashOutRequest $request, bool $accepted): void
    {
        $request->loadMissing('member.user');

        $notification = $accepted
            ? new CashOutRequestAcceptedNotification($request)
            : new CashOutRequestRejectedNotification($request);

        $request->member->user?->notify($notification);
    }

    private function notifyAdminsOfNewRequest(CashOutRequest $request): void
    {
        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($request): void {
                $admin->notify(new NewCashOutRequestNotification($request));
            });
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
