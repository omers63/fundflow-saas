<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MemberAnnualSubscriptionFeeService
{
    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    public function charge(Member $member, ?DateTimeInterface $transactedAt = null): FeeDeduction
    {
        $amount = ContributionPolicySettings::annualSubscriptionFee();

        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Annual subscription fee is not configured.'));
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
            throw new InvalidArgumentException(__('Insufficient member cash balance for the annual subscription fee.'));
        }

        $transactedAt = $transactedAt ?? BusinessDay::now();
        $description = __('Annual subscription fee — :name', ['name' => $member->name]);

        return ReconciliationService::withoutRealtimeChecks(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
            return AccountingService::withoutMemberCashCollection(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
                return DB::transaction(function () use ($member, $memberCash, $masterFees, $amount, $description, $transactedAt): FeeDeduction {
                    $deduction = FeeDeduction::create([
                        'member_id' => $member->id,
                        'amount' => $amount,
                        'description' => $description,
                        'transacted_at' => $transactedAt,
                    ]);

                    $this->accounting->debitMemberCashWithMasterMirror(
                        $memberCash,
                        $amount,
                        $description,
                        __('(annual subscription fee mirror)'),
                        $deduction,
                        $transactedAt,
                        $member->id,
                    );

                    $this->accounting->credit(
                        $masterFees,
                        $amount,
                        $description,
                        $deduction,
                        $transactedAt,
                    );

                    return $deduction->fresh(['member']);
                });
            });
        });
    }
}
