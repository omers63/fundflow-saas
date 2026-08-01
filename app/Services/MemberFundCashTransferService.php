<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class MemberFundCashTransferService
{
    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    public function transferPositiveFundBalanceToCash(
        Member $member,
        Model $reference,
        string $description,
        ?CarbonInterface $transactedAt = null,
    ): float {
        $this->accounting->createMemberAccounts($member);
        $member->unsetRelation('fundAccount');
        $member->unsetRelation('cashAccount');
        $member->unsetRelation('accounts');
        $member->load(['cashAccount', 'fundAccount']);

        $amount = round(max(0.0, $member->getFundBalance()), 2);

        if ($amount <= 0.00001) {
            return 0.0;
        }

        $this->transferAmount($member, $amount, $reference, $description, $transactedAt);

        return $amount;
    }

    public function transferAmount(
        Member $member,
        float $amount,
        Model $reference,
        string $description,
        ?CarbonInterface $transactedAt = null,
    ): void {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Enter an amount greater than zero.'));
        }

        $at = $transactedAt ?? BusinessDay::now();

        DB::transaction(function () use ($member, $amount, $description, $reference, $at): void {
            $member->unsetRelation('fundAccount');
            $member->unsetRelation('cashAccount');
            $member->unsetRelation('accounts');
            $member->load(['cashAccount', 'fundAccount']);

            $memberFund = $member->fundAccount;
            $memberCash = $member->cashAccount;

            if ($memberFund === null || $memberCash === null) {
                throw new RuntimeException(__('Member fund and cash accounts are required.'));
            }

            if ((float) $memberFund->balance < $amount - 0.00001) {
                throw new InvalidArgumentException(__('Insufficient fund balance for the requested transfer.'));
            }

            $this->accounting->debitMemberFundWithMasterMirror(
                $memberFund,
                $amount,
                $description,
                __('(master fund mirror)'),
                $reference,
                $at,
                $member->id,
            );
            $this->accounting->creditMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description,
                __('(master cash mirror)'),
                $reference,
                $at,
                $member->id,
            );
        });
    }
}
