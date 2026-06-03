<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MasterFeeDeductionService
{
    public function __construct(
        private AccountingService $accounting,
        private MemberFeeArrearsService $arrears,
    ) {}

    public function deduct(
        Member $member,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): FeeDeduction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $member->loadMissing('cashAccount');
        $memberCash = $member->cashAccount;
        $masterFees = Account::masterFees();

        if ($memberCash === null) {
            throw new InvalidArgumentException(__('Member cash account is not configured.'));
        }

        if ($masterFees === null) {
            throw new InvalidArgumentException(__('Master fees account is not configured.'));
        }

        if ($amount > (float) $memberCash->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the member\'s available cash balance.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $transactedAt ?? now();

        return ReconciliationService::withoutRealtimeChecks(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
            return AccountingService::withoutMemberCashCollection(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
                return DB::transaction(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
                    $deduction = FeeDeduction::create([
                        'member_id' => $member->id,
                        'amount' => $amount,
                        'description' => $description,
                        'transacted_at' => $transactedAt,
                    ]);

                    $remaining = $amount;

                    foreach ($this->arrears->subscriptionArrears($member) as $entry) {
                        if ($remaining <= 0.00001) {
                            break;
                        }

                        /** @var MembershipApplication $application */
                        $application = $entry['application'];
                        $apply = min($remaining, $entry['arrears']);

                        if ($apply <= 0.00001) {
                            continue;
                        }

                        $this->applySubscriptionArrearsPayment(
                            $application,
                            $member,
                            $memberCash,
                            $masterFees,
                            $apply,
                            $transactedAt,
                        );

                        $remaining = round($remaining - $apply, 2);
                    }

                    foreach ($this->arrears->contributionLateFeeArrears($member) as $entry) {
                        if ($remaining <= 0.00001) {
                            break;
                        }

                        $apply = min($remaining, $entry['outstanding']);

                        if ($apply <= 0.00001) {
                            continue;
                        }

                        $this->accounting->postContributionLateFee($entry['contribution'], $apply);
                        $remaining = round($remaining - $apply, 2);
                    }

                    foreach ($this->arrears->installmentLateFeeArrears($member) as $entry) {
                        if ($remaining <= 0.00001) {
                            break;
                        }

                        $apply = min($remaining, $entry['outstanding']);

                        if ($apply <= 0.00001) {
                            continue;
                        }

                        $this->accounting->postInstallmentLateFee($entry['installment'], $apply);
                        $remaining = round($remaining - $apply, 2);
                    }

                    if ($remaining > 0.00001) {
                        $feeDescription = __('Fee deduction — :name: :description', [
                            'name' => $member->name,
                            'description' => $description,
                        ]);

                        $this->accounting->debitMemberCashWithMasterMirror(
                            $memberCash,
                            $remaining,
                            $feeDescription,
                            __('(fee deduction mirror)'),
                            $deduction,
                            $transactedAt,
                            $member->id,
                        );

                        $this->accounting->credit(
                            $masterFees,
                            $remaining,
                            $feeDescription,
                            $deduction,
                            $transactedAt,
                        );
                    }

                    return $deduction->fresh(['member']);
                });
            });
        });
    }

    private function applySubscriptionArrearsPayment(
        MembershipApplication $application,
        Member $member,
        Account $memberCash,
        Account $masterFees,
        float $amount,
        DateTimeInterface $transactedAt,
    ): void {
        $required = (float) ($application->membership_fee_required_amount ?? 0);
        $transferred = (float) ($application->membership_fee_amount ?? 0);
        $newTransferred = min($required, round($transferred + $amount, 2));
        $remainingArrears = max(0.0, round($required - $newTransferred, 2));

        $feeDescription = __('Subscription fee — :name', ['name' => $member->name]);

        $this->accounting->debitMemberCashWithMasterMirror(
            $memberCash,
            $amount,
            $feeDescription,
            __('(subscription fee mirror)'),
            $application,
            $transactedAt,
            $member->id,
        );

        $this->accounting->credit(
            $masterFees,
            $amount,
            $feeDescription,
            $application,
            $transactedAt,
        );

        $application->update([
            'membership_fee_amount' => $newTransferred,
            'rejection_reason' => $remainingArrears > 0.00001
                ? __('Subscription fee arrears: :amount', ['amount' => number_format($remainingArrears, 2)])
                : null,
        ]);
    }
}
