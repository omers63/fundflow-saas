<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\User;
use App\Services\FundAuditLogService;
use App\Services\MemberOpeningBalanceService;
use App\Support\BusinessDay;
use App\Support\FiscalSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FiscalCloseRollForwardService
{
    public function __construct(
        protected MemberOpeningBalanceService $openingBalances,
        protected FundAuditLogService $audit,
        protected FiscalClosePeriodResolver $periodResolver,
    ) {}

    public function execute(FiscalClose $close, User $approver): FiscalClose
    {
        if (! $close->canRollForward()) {
            throw new InvalidArgumentException(__('This fiscal close cannot be rolled forward (status: :status).', [
                'status' => $close->status,
            ]));
        }

        $readiness = $close->readiness_report_json ?? [];
        if (($readiness['can_proceed'] ?? false) !== true) {
            throw new InvalidArgumentException(__('Readiness checks did not pass when the snapshot was built.'));
        }

        $entryLabel = 'FISCAL_CLOSE_'.$close->fiscal_year_label;
        $periodEnd = $close->period_end->copy()->startOfDay();

        DB::transaction(function () use ($close, $approver, $entryLabel, $periodEnd): void {
            $close->memberSnapshots()
                ->orderBy('member_id')
                ->chunkById(100, function ($snapshots) use ($entryLabel): void {
                    foreach ($snapshots as $snapshot) {
                        $this->rollMemberOpeningBalances($snapshot, $entryLabel);
                    }
                });

            FiscalSettings::applyBooksClosedThrough($periodEnd);

            $nextPeriod = $this->periodResolver->nextPeriodAfter(
                new FiscalYearPeriod(
                    $close->fiscal_year_label,
                    $close->period_start->copy()->startOfDay(),
                    $close->period_end->copy()->endOfDay(),
                ),
            );
            FiscalSettings::setCurrentFiscalYearLabel($nextPeriod->label);

            $close->update([
                'status' => FiscalClose::STATUS_ROLLED_FORWARD,
                'approved_by' => $approver->id,
                'approved_at' => BusinessDay::now(),
                'closed_by' => $close->closed_by ?? $approver->id,
                'closed_at' => $close->closed_at ?? BusinessDay::now(),
            ]);
        });

        $this->audit->log('FISCAL_CLOSE_ROLLED_FORWARD', 'fiscal_close', $close, null, [
            'fiscal_year_label' => $close->fiscal_year_label,
            'period_end' => $periodEnd->toDateString(),
            'member_count' => $close->member_count,
            'checksum' => $close->checksum,
            'approved_by' => $approver->id,
        ]);

        return $close->fresh();
    }

    public function rollMemberOpeningBalances(FiscalCloseMemberSnapshot $snapshot, string $entryLabel): void
    {
        $member = $snapshot->member()->first();

        if ($member === null) {
            return;
        }

        $this->openingBalances->rollForwardForFiscalClose(
            $member,
            (float) $snapshot->cash_balance,
            (float) $snapshot->fund_balance,
            $entryLabel,
        );
    }
}
