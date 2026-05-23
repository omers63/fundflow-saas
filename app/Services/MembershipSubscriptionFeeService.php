<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\Transaction;
use App\Support\PublicPageSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MembershipSubscriptionFeeService
{
    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    public function applicationRequiresSubscriptionFee(MembershipApplication $application): bool
    {
        if ($application->isHouseholdDependent()) {
            return false;
        }

        return $this->requiredFeeAmount($application) > 0;
    }

    public function requiredFeeAmount(MembershipApplication $application): float
    {
        $stored = $application->membership_fee_required_amount;

        if ($stored !== null && (float) $stored > 0) {
            return (float) $stored;
        }

        return PublicPageSettings::feeForType((string) $application->application_type);
    }

    public function assertCanApprove(MembershipApplication $application): void
    {
        if (! $this->applicationRequiresSubscriptionFee($application)) {
            return;
        }

        if (blank($application->membership_fee_transfer_reference)) {
            throw new InvalidArgumentException(__('A transfer reference is required before this application can be approved.'));
        }

        $transferred = (float) ($application->membership_fee_amount ?? 0);
        $required = $this->requiredFeeAmount($application);

        if ($transferred < $required) {
            throw new InvalidArgumentException(__(
                'The declared transfer amount (:transferred) is less than the required subscription fee (:required). Approval is not allowed until the member transfers at least the required amount.',
                [
                    'transferred' => number_format($transferred, 2),
                    'required' => number_format($required, 2),
                ],
            ));
        }
    }

    /**
     * Post subscription fee accounting when a membership application is approved.
     *
     * Credits master cash (uncleared bank item), mirrors to member cash, then allocates
     * the required fee amount from member cash to the master fees account.
     */
    public function postOnApproval(MembershipApplication $application, Member $member): void
    {
        if (! $this->applicationRequiresSubscriptionFee($application)) {
            return;
        }

        if (BankTransaction::query()->where('membership_application_id', $application->id)->exists()) {
            throw new InvalidArgumentException(__('Subscription fee accounting has already been posted for this application.'));
        }

        $this->assertCanApprove($application);

        $transferred = (float) $application->membership_fee_amount;
        $required = $this->requiredFeeAmount($application);

        $masterCash = Account::masterCash();
        $masterFees = Account::masterFees();
        $memberCash = $member->cashAccount;

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        if ($masterFees === null) {
            throw new InvalidArgumentException(__('Master fees account is not configured.'));
        }

        if ($memberCash === null) {
            throw new InvalidArgumentException(__('Member cash account is not configured.'));
        }

        DB::transaction(function () use ($application, $member, $transferred, $required, $masterCash, $masterFees, $memberCash): void {
            $bankTxn = $this->createUnclearedBankTransaction($application, $member, $transferred);

            $receiptDescription = __('Subscription fees — :name (application #:id)', [
                'name' => $member->name,
                'id' => $application->id,
            ]);

            $masterCredit = $this->accounting->credit(
                $masterCash,
                $transferred,
                $receiptDescription,
                $application,
                $application->membership_fee_transfer_date,
                $member->id,
            );

            $bankTxn->forceFill([
                'master_cash_transaction_id' => $masterCredit->id,
                'status' => 'posted',
            ])->save();

            $mirrorDescription = __('Posted: :description', ['description' => $receiptDescription]);
            $this->accounting->mirror($memberCash, $transferred, $mirrorDescription, $application);

            app(ContributionCollectionCycleService::class)->onMemberCashIncreased($member);

            $feeDescription = __('Subscription fee — :name', ['name' => $member->name]);
            $this->accounting->transfer(
                $memberCash,
                $masterFees,
                $required,
                $feeDescription,
                $application,
            );
        });
    }

    private function createUnclearedBankTransaction(
        MembershipApplication $application,
        Member $member,
        float $amount,
    ): BankTransaction {
        $statement = BankStatement::firstOrCreate(
            ['filename' => 'membership-subscription-fees', 'status' => 'completed'],
            [
                'bank_name' => __('Membership subscription fees'),
                'total_rows' => 0,
                'imported_rows' => 0,
                'duplicate_rows' => 0,
            ],
        );

        $transferDate = $application->membership_fee_transfer_date?->toDateString()
            ?? now()->toDateString();

        return BankTransaction::create([
            'bank_statement_id' => $statement->id,
            'transaction_date' => $transferDate,
            'description' => __('Subscription fees — :name', ['name' => $member->name]),
            'amount' => $amount,
            'reference' => $application->membership_fee_transfer_reference,
            'status' => 'imported',
            'member_id' => $member->id,
            'hash' => md5("membership-fee-{$application->id}-{$transferDate}-{$amount}"),
            'is_cleared' => false,
            'membership_application_id' => $application->id,
        ]);
    }

    public function unclearedBankTransaction(MembershipApplication $application): ?BankTransaction
    {
        return BankTransaction::query()
            ->where('membership_application_id', $application->id)
            ->where('is_cleared', false)
            ->first();
    }

    public function masterCashCreditTransaction(MembershipApplication $application): ?Transaction
    {
        $bankTxn = $this->unclearedBankTransaction($application);

        if ($bankTxn !== null) {
            return $bankTxn->resolveMasterCashTransaction();
        }

        return Transaction::query()
            ->where('reference_type', MembershipApplication::class)
            ->where('reference_id', $application->id)
            ->whereHas('account', fn ($query) => $query->where('is_master', true)->where('type', 'cash'))
            ->where('type', 'credit')
            ->orderBy('id')
            ->first();
    }
}
