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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MemberCashOutService
{
    public function __construct(
        private AccountingService $accounting,
    ) {}

    public function availableCashForWithdrawal(Member $member, ?CashOutRequest $excludeRequest = null): float
    {
        $balance = max(0.0, $this->cashBalanceFor($member));
        $reserved = $this->reservedForNextEmi($member);
        $pendingQuery = CashOutRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending');

        if ($excludeRequest !== null && $excludeRequest->exists) {
            $pendingQuery->whereKeyNot($excludeRequest->getKey());
        }

        $pending = (float) $pendingQuery->sum('amount');

        return max(0.0, round($balance - $reserved - $pending, 2));
    }

    private function cashBalanceFor(Member $member): float
    {
        return (float) (Account::query()
            ->where('member_id', $member->id)
            ->where('type', 'cash')
            ->where('is_master', false)
            ->orderBy('id')
            ->value('balance') ?? 0);
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

    public function submit(Member $member, float $amount, ?string $notes = null): CashOutRequest
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a withdrawal amount greater than zero.'));
        }

        $available = $this->availableCashForWithdrawal($member);

        if ($amount > $available + 0.01) {
            throw new InvalidArgumentException(__('Amount exceeds available cash (:available).', [
                'available' => number_format($available, 2),
            ]));
        }

        return DB::transaction(function () use ($member, $amount, $notes): CashOutRequest {
            $request = CashOutRequest::create([
                'member_id' => $member->id,
                'amount' => $amount,
                'notes' => $notes,
                'status' => 'pending',
            ]);

            User::query()->where('is_admin', true)->each(
                fn (User $admin): mixed => $admin->notify(new NewCashOutRequestNotification($request)),
            );

            return $request;
        });
    }

    public function accept(CashOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending cash-out requests can be accepted.'));
        }

        $request->loadMissing('member');
        $member = $request->member;
        $memberCash = Account::query()
            ->where('member_id', $member->id)
            ->where('type', 'cash')
            ->where('is_master', false)
            ->orderBy('id')
            ->first();
        $masterCash = Account::masterCash();
        $amount = (float) $request->amount;

        if ($memberCash === null || $masterCash === null) {
            throw new RuntimeException(__('Required cash accounts are not configured.'));
        }

        if ($amount > $this->availableCashForWithdrawal($member, $request) + 0.01) {
            throw new InvalidArgumentException(__('Member no longer has enough available cash for this request.'));
        }

        DB::transaction(function () use ($request, $member, $memberCash, $masterCash, $amount, $reviewedBy, $remarks): void {
            $description = __('Cash out #:id – :name', [
                'id' => $request->id,
                'name' => $member->name,
            ]);

            $this->accounting->debit(
                $memberCash,
                $amount,
                $description.' '.__('(cash out)'),
                $request,
                null,
                $member->id,
            );
            $this->accounting->debit(
                $masterCash,
                $amount,
                $description.' '.__('(cash out mirror)'),
                $request,
            );

            $statement = BankStatement::firstOrCreate(
                ['filename' => 'member-cash-outs', 'status' => 'completed'],
                [
                    'bank_name' => __('Member cash outs'),
                    'total_rows' => 0,
                    'imported_rows' => 0,
                    'duplicate_rows' => 0,
                ],
            );

            $bankTxn = BankTransaction::create([
                'bank_statement_id' => $statement->id,
                'transaction_date' => now()->toDateString(),
                'description' => $description,
                'amount' => -$amount,
                'reference' => (string) $request->id,
                'status' => 'imported',
                'member_id' => $member->id,
                'hash' => md5("cash-out-{$request->id}-{$amount}"),
                'is_cleared' => false,
                'cash_out_request_id' => $request->id,
            ]);

            $request->update([
                'status' => 'accepted',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'admin_remarks' => $remarks,
                'bank_transaction_id' => $bankTxn->id,
            ]);

            $member->loadMissing('user');
            $member->user?->notify(new CashOutRequestAcceptedNotification($request));
        });
    }

    /**
     * Clear an uncleared cash-out bank transaction by matching it to an imported statement line.
     */
    public function clearTransaction(BankTransaction $uncleared, BankTransaction $imported): void
    {
        DB::transaction(function () use ($uncleared, $imported): void {
            $uncleared->update([
                'is_cleared' => true,
                'cleared_at' => now(),
            ]);

            $imported->update([
                'is_cleared' => true,
                'cleared_at' => now(),
                'cash_out_request_id' => $uncleared->cash_out_request_id,
            ]);
        });
    }

    public function reject(CashOutRequest $request, ?int $reviewedBy = null, ?string $remarks = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending cash-out requests can be rejected.'));
        }

        if ($remarks === null || trim($remarks) === '') {
            throw new InvalidArgumentException(__('Provide a reason for rejection.'));
        }

        DB::transaction(function () use ($request, $reviewedBy, $remarks): void {
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
                'admin_remarks' => $remarks,
            ]);

            $request->loadMissing('member.user');
            $request->member->user?->notify(new CashOutRequestRejectedNotification($request));
        });
    }
}
