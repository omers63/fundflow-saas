<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
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
        if ($application->isHouseholdDependent() && ! $application->wasImportedFromCsv()) {
            return false;
        }

        if ((float) ($application->membership_fee_amount ?? 0) > 0) {
            return true;
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

        if (
            blank($application->membership_fee_transfer_reference)
            && ! $application->wasImportedFromCsv()
        ) {
            throw new InvalidArgumentException(__('A transfer reference is required before this application can be approved.'));
        }

    }

    /**
     * Post subscription fee accounting when a membership application is approved.
     *
     * Mirrors transfer to member and master cash, then allocates the required fee to master fees.
     * Does not create bank statement lines or master bank ledger entries.
     */
    public function postOnApproval(MembershipApplication $application, Member $member): void
    {
        if (! $this->applicationRequiresSubscriptionFee($application)) {
            $application->update(['rejection_reason' => null]);

            return;
        }

        if ($this->hasSubscriptionFeePosted($application)) {
            throw new InvalidArgumentException(__('Subscription fee accounting has already been posted for this application.'));
        }

        $this->assertCanApprove($application);

        $transferred = (float) $application->membership_fee_amount;
        $required = $this->requiredFeeAmount($application);
        $settledFee = min($transferred, $required);
        $arrears = max(0.0, round($required - $settledFee, 2));

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

        $transferDate = $application->membership_fee_transfer_date ?? now();

        $postSubscriptionFee = function () use ($application, $member, $transferred, $settledFee, $arrears, $masterFees, $memberCash, $transferDate): void {
            DB::transaction(function () use ($application, $member, $transferred, $settledFee, $arrears, $masterFees, $memberCash, $transferDate): void {
                $receiptDescription = __('Subscription fees — :name (application #:id)', [
                    'name' => $member->name,
                    'id' => $application->id,
                ]);

                if ($transferred > 0.00001) {
                    $this->accounting->creditMemberCashWithMasterMirror(
                        $memberCash,
                        $transferred,
                        __('Posted: :description', ['description' => $receiptDescription]),
                        __('(subscription deposit mirror)'),
                        $application,
                        $transferDate,
                        $member->id,
                    );
                }

                if ($settledFee > 0) {
                    $feeDescription = __('Subscription fee — :name', ['name' => $member->name]);
                    $this->accounting->debitMemberCashWithMasterMirror(
                        $memberCash,
                        $settledFee,
                        $feeDescription,
                        __('(subscription fee mirror)'),
                        $application,
                        $transferDate,
                        $member->id,
                    );
                    $this->accounting->credit(
                        $masterFees,
                        $settledFee,
                        $feeDescription,
                        $application,
                        $transferDate,
                    );
                }

                $application->update([
                    'rejection_reason' => $arrears > 0
                        ? __('Subscription fee arrears: :amount', ['amount' => number_format($arrears, 2)])
                        : null,
                ]);
            });
        };

        if ($application->wasImportedFromCsv()) {
            AccountingService::withoutMemberCashCollection($postSubscriptionFee);

            return;
        }

        $postSubscriptionFee();
    }

    public function masterCashCreditTransaction(MembershipApplication $application): ?Transaction
    {
        $masterCashId = Account::masterCash()?->id;

        if ($masterCashId === null) {
            return null;
        }

        return Transaction::query()
            ->where('account_id', $masterCashId)
            ->where('type', 'credit')
            ->where('description', 'like', '%(subscription deposit mirror)%')
            ->where('description', 'like', '%application #:'.$application->id.'%')
            ->orderByDesc('id')
            ->first();
    }

    protected function hasSubscriptionFeePosted(MembershipApplication $application): bool
    {
        return $this->masterCashCreditTransaction($application) !== null;
    }
}
