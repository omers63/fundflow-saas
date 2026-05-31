<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MigrationOpeningBalanceService
{
    public function __construct(
        protected AccountingService $accounting,
        protected FundAuditLogService $audit,
    ) {}

    /**
     * Post MIGRATION_OPENING cash and fund balances per fund_management_system_requirements §4.2.
     */
    public function postOpeningBalances(
        Member $member,
        float $cashBalance,
        float $fundBalance,
        ?Carbon $effectiveDate = null,
        string $entryLabel = 'MIGRATION_OPENING',
    ): void {
        if ($member->opening_balances_posted_at !== null) {
            throw new InvalidArgumentException(__('Opening balances were already posted for this member.'));
        }

        $effectiveDate ??= $member->migration_cutoff_date
            ? Carbon::parse($member->migration_cutoff_date)
            : now();

        $member->loadMissing('accounts');
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;
        $masterCash = Account::masterCash();
        $masterFund = Account::masterFund();

        if ($memberCash === null || $memberFund === null || $masterCash === null || $masterFund === null) {
            throw new InvalidArgumentException(__('Member and master accounts must exist before posting opening balances.'));
        }

        DB::transaction(function () use ($member, $cashBalance, $fundBalance, $effectiveDate, $memberCash, $memberFund, $masterCash, $masterFund, $entryLabel): void {
            AccountingService::withoutMemberCashCollection(function () use ($member, $cashBalance, $fundBalance, $effectiveDate, $memberCash, $memberFund, $masterCash, $masterFund, $entryLabel): void {
                if ($cashBalance > 0.00001) {
                    $desc = __(':label — cash — :name', ['label' => $entryLabel, 'name' => $member->name]);
                    $this->accounting->credit($masterCash, $cashBalance, $desc, null, $effectiveDate, $member->id);
                    $this->accounting->credit($memberCash, $cashBalance, $desc, null, $effectiveDate, $member->id);
                }

                if ($fundBalance > 0.00001) {
                    $desc = __(':label — fund — :name', ['label' => $entryLabel, 'name' => $member->name]);
                    $this->accounting->credit($masterFund, $fundBalance, $desc, null, $effectiveDate, $member->id);
                    $this->accounting->credit($memberFund, $fundBalance, $desc, null, $effectiveDate, $member->id);
                }

                $member->update([
                    'opening_cash_balance' => $cashBalance,
                    'opening_fund_balance' => $fundBalance,
                    'opening_balances_posted_at' => now(),
                ]);
            });
        });

        if ($cashBalance > 0.00001) {
            $this->accounting->triggerMemberCashCollection($member->fresh() ?? $member);
        }

        $this->audit->log($entryLabel === 'MIGRATION_OPENING' ? 'MIGRATION_OPENING_POSTED' : 'IMPORT_CUTOFF_POSTED', 'membership', $member, $member, [
            'cash' => $cashBalance,
            'fund' => $fundBalance,
            'effective' => $effectiveDate->toDateString(),
            'entry_label' => $entryLabel,
        ]);
    }
}
