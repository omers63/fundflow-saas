<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\MigrationInstalmentSchedule;
use App\Support\ContributionPolicySettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MigrationSettlementService
{
    public function __construct(
        protected AccountingService $accounting,
        protected FundAuditLogService $audit,
        protected MigrationCycleService $migration,
    ) {}

    public function applyLumpSumSettlement(Member $member): float
    {
        $stubs = $this->backdatedDueStubs($member);

        if ($stubs->isEmpty()) {
            throw new InvalidArgumentException(__('No backdated due stubs to settle.'));
        }

        $total = (float) $stubs->sum('amount_due');
        $cash = $member->getCashBalance();

        if ($cash < $total - 0.00001) {
            throw new InvalidArgumentException(__('Insufficient cash for lump-sum migration settlement.'));
        }

        DB::transaction(function () use ($member, $stubs, $total): void {
            $memberCash = $member->cashAccount;
            $memberFund = $member->fundAccount;
            $masterFund = Account::masterFund();

            if ($memberCash === null || $memberFund === null || $masterFund === null) {
                throw new InvalidArgumentException(__('Member accounts are not configured.'));
            }

            $description = __('MIGRATION_LUMPSUM — :name', ['name' => $member->name]);

            $this->accounting->debit($memberCash, $total, $description);
            $this->accounting->credit($masterFund, $total, $description);
            $this->accounting->credit($memberFund, $total, $description);

            foreach ($stubs as $stub) {
                $this->migration->classifyStub(
                    $stub,
                    MigrationCycleStub::CLASS_BACKDATED_DUE,
                    'lumpsum',
                    __('Settled via lump sum'),
                );
                $stub->update(['status' => 'closed']);
            }
        });

        $this->audit->log('MIGRATION_LUMPSUM', 'migration', $member, $member, ['amount' => $total]);

        return $total;
    }

    public function buildInstalmentPlan(Member $member): int
    {
        $stubs = $this->backdatedDueStubs($member);

        if ($stubs->isEmpty()) {
            throw new InvalidArgumentException(__('No backdated due stubs for instalment plan.'));
        }

        $total = (float) $stubs->sum('amount_due');
        $cycles = ContributionPolicySettings::migrationInstalmentCycles();
        $instalment = (float) ceil($total / $cycles);
        $cursor = now()->addMonthNoOverflow()->startOfMonth();
        $created = 0;

        MigrationInstalmentSchedule::query()->where('member_id', $member->id)->delete();

        for ($i = 0; $i < $cycles; $i++) {
            $amount = $i < $cycles - 1
                ? $instalment
                : max(0.0, $total - ($instalment * ($cycles - 1)));

            MigrationInstalmentSchedule::create([
                'member_id' => $member->id,
                'cycle_date' => $cursor->toDateString(),
                'amount' => round($amount, 2),
                'status' => 'pending',
            ]);

            $cursor = $cursor->copy()->addMonthNoOverflow();
            $created++;
        }

        foreach ($stubs as $stub) {
            $stub->update([
                'classification' => MigrationCycleStub::CLASS_BACKDATED_DUE,
                'resolution_method' => 'instalment',
                'status' => 'closed',
            ]);
        }

        $this->audit->log('MIGRATION_INSTALMENT_PLAN', 'migration', $member, $member, [
            'cycles' => $created,
            'total' => $total,
        ]);

        return $created;
    }

    public function applyOpeningBalanceOffset(Member $member): float
    {
        $stubs = $this->backdatedDueStubs($member);
        $total = (float) $stubs->sum('amount_due');
        $fundBal = (float) ($member->fundAccount?->balance ?? 0);

        if ($fundBal < $total - 0.00001) {
            throw new InvalidArgumentException(__('Fund balance is insufficient for opening balance offset.'));
        }

        $masterFund = Account::masterFund();
        $memberFund = $member->fundAccount;

        if ($masterFund === null || $memberFund === null) {
            throw new InvalidArgumentException(__('Fund accounts are not configured.'));
        }

        DB::transaction(function () use ($member, $stubs, $total, $masterFund, $memberFund): void {
            $description = __('MIGRATION_OB_OFFSET — :name', ['name' => $member->name]);
            $this->accounting->debit($memberFund, $total, $description);
            $this->accounting->credit($masterFund, $total, $description);

            foreach ($stubs as $stub) {
                $stub->update([
                    'classification' => MigrationCycleStub::CLASS_BACKDATED_DUE,
                    'resolution_method' => 'ob_offset',
                    'status' => 'closed',
                ]);
            }
        });

        $this->audit->log('MIGRATION_OB_OFFSET', 'migration', $member, $member, ['amount' => $total]);

        return $total;
    }

    /**
     * @return Collection<int, MigrationCycleStub>
     */
    protected function backdatedDueStubs(Member $member)
    {
        return MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->where(function ($q): void {
                $q->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('classification')
                            ->where('status', MigrationCycleStub::STATUS_UNRESOLVED);
                    });
            })
            ->get();
    }
}
