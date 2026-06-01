<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MemberOpeningBalanceService
{
    public function __construct(
        protected AccountingService $accounting,
        protected FundAuditLogService $audit,
    ) {}

    /**
     * Post import cut-off cash and fund balances when a CSV member is approved.
     */
    public function postOpeningBalances(
        Member $member,
        float $cashBalance,
        float $fundBalance,
        Carbon $effectiveDate,
        string $entryLabel = 'IMPORT_CUTOFF',
    ): void {
        if ($member->opening_balances_posted_at !== null) {
            throw new InvalidArgumentException(__('Opening balances were already posted for this member.'));
        }

        $member->loadMissing('accounts');
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;
        $masterCash = Account::masterCash();
        $masterFund = Account::masterFund();

        if ($memberCash === null || $memberFund === null || $masterCash === null || $masterFund === null) {
            throw new InvalidArgumentException(__('Member and master accounts must exist before posting opening balances.'));
        }

        DB::transaction(function () use ($member, $cashBalance, $fundBalance, $effectiveDate, $memberCash, $memberFund, $entryLabel): void {
            AccountingService::withoutMemberCashCollection(function () use ($member, $cashBalance, $fundBalance, $effectiveDate, $memberCash, $memberFund, $entryLabel): void {
                $mirrorSuffix = __('(opening balance mirror)');

                if (abs($cashBalance) > 0.00001) {
                    $desc = __(':label — cash — :name', ['label' => $entryLabel, 'name' => $member->name]);
                    $amount = abs($cashBalance);

                    if ($cashBalance > 0) {
                        $this->accounting->creditMemberCashWithMasterMirror(
                            $memberCash,
                            $amount,
                            $desc,
                            $mirrorSuffix,
                            null,
                            $effectiveDate,
                            $member->id,
                        );
                    } else {
                        $this->accounting->debitMemberCashWithMasterMirror(
                            $memberCash,
                            $amount,
                            $desc,
                            $mirrorSuffix,
                            null,
                            $effectiveDate,
                            $member->id,
                        );
                    }
                }

                if (abs($fundBalance) > 0.00001) {
                    $desc = __(':label — fund — :name', ['label' => $entryLabel, 'name' => $member->name]);
                    $amount = abs($fundBalance);

                    if ($fundBalance > 0) {
                        $this->accounting->creditMemberFundWithMasterMirror(
                            $memberFund,
                            $amount,
                            $desc,
                            $mirrorSuffix,
                            null,
                            $effectiveDate,
                            $member->id,
                        );
                    } else {
                        $this->accounting->debitMemberFundWithMasterMirror(
                            $memberFund,
                            $amount,
                            $desc,
                            $mirrorSuffix,
                            null,
                            $effectiveDate,
                            $member->id,
                        );
                    }
                }

                $member->update([
                    'opening_cash_balance' => $cashBalance,
                    'opening_fund_balance' => $fundBalance,
                    'opening_balances_posted_at' => now(),
                ]);
            });
        });

        if ($cashBalance > 0.00001 && $entryLabel !== 'IMPORT_CUTOFF') {
            $this->accounting->triggerMemberCashCollection($member->fresh() ?? $member);
        }

        $this->audit->log('IMPORT_CUTOFF_POSTED', 'membership', $member, $member, [
            'cash' => $cashBalance,
            'fund' => $fundBalance,
            'effective' => $effectiveDate->toDateString(),
            'entry_label' => $entryLabel,
        ]);
    }
}
