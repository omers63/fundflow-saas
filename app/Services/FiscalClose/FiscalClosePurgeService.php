<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\FundAuditLogService;
use App\Services\MasterAccountInvariantService;
use App\Services\MemberInvariantService;
use App\Services\ReconciliationService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FiscalClosePurgeService
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        protected MasterAccountInvariantService $masterInvariants,
        protected MemberInvariantService $memberInvariants,
        protected FundAuditLogService $audit,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function executeTierA(FiscalClose $close): array
    {
        $this->assertCanPurgeTierA($close);

        $periodEnd = $close->period_end->copy()->endOfDay();
        $startedAt = BusinessDay::now();

        $close->update(['purge_started_at' => $startedAt]);

        $counts = ReconciliationService::withoutRealtimeChecks(function () use ($periodEnd): array {
            return DB::transaction(function () use ($periodEnd): array {
                $this->detachBankLinesFromLedger($periodEnd);

                return [
                    'transactions' => $this->purgeTransactionsThrough($periodEnd),
                    'bank_transactions' => $this->purgeClearedBankLinesThrough($periodEnd),
                    'reconciliation_exceptions' => $this->purgeResolvedReconciliationExceptions(),
                ];
            });
        });

        $this->assertPostPurgeInvariants($close);

        $summary = $close->purge_summary_json ?? [];
        $summary['tier_a'] = array_merge($counts, [
            'completed_at' => BusinessDay::now()->toIso8601String(),
        ]);

        $updates = [
            'purge_summary_json' => $summary,
        ];

        if (FiscalSettings::includesTierBPurge()) {
            $updates['purge_completed_at'] = null;
        } else {
            $updates['status'] = FiscalClose::STATUS_PURGED;
            $updates['purge_completed_at'] = BusinessDay::now();
        }

        $close->update($updates);

        $this->audit->log('FISCAL_CLOSE_TIER_A_PURGE_COMPLETED', 'fiscal_close', $close, null, [
            'fiscal_year_label' => $close->fiscal_year_label,
            'period_end' => $close->period_end->toDateString(),
            'summary' => $counts,
        ]);

        return array_merge($counts, ['tier' => 'a']);
    }

    /**
     * @return array<string, int|string>
     */
    public function executeTierB(FiscalClose $close): array
    {
        if (! FiscalSettings::includesTierBPurge()) {
            throw new InvalidArgumentException(__('Tier B purge is disabled by the current retention policy.'));
        }

        if ($close->status !== FiscalClose::STATUS_ROLLED_FORWARD) {
            throw new InvalidArgumentException(__('Tier B purge requires a rolled-forward close (status: :status).', [
                'status' => $close->status,
            ]));
        }

        if (! $this->tierACompleted($close)) {
            throw new InvalidArgumentException(__('Tier A purge must complete before Tier B.'));
        }

        if (blank($close->checksum) || $close->memberSnapshots()->count() === 0) {
            throw new InvalidArgumentException(__('A certified member snapshot is required before purge.'));
        }

        $periodEnd = $close->period_end->copy()->endOfDay();

        $counts = DB::transaction(function () use ($periodEnd): array {
            return [
                'contributions' => $this->purgePostedContributionsThrough($periodEnd),
                'loan_installments' => $this->purgePaidLoanInstallmentsThrough($periodEnd),
                'fund_postings' => $this->purgeClosedFundPostingsThrough($periodEnd),
                'fund_audit_log' => $this->purgeFundAuditLogThrough($periodEnd),
            ];
        });

        $summary = $close->purge_summary_json ?? [];
        $summary['tier_b'] = array_merge($counts, [
            'completed_at' => BusinessDay::now()->toIso8601String(),
        ]);

        $close->update([
            'status' => FiscalClose::STATUS_PURGED,
            'purge_completed_at' => BusinessDay::now(),
            'purge_summary_json' => $summary,
        ]);

        $this->audit->log('FISCAL_CLOSE_TIER_B_PURGE_COMPLETED', 'fiscal_close', $close, null, [
            'fiscal_year_label' => $close->fiscal_year_label,
            'period_end' => $close->period_end->toDateString(),
            'summary' => $counts,
        ]);

        return array_merge($counts, ['tier' => 'b']);
    }

    public function assertCanPurgeTierA(FiscalClose $close): void
    {
        if ($close->status !== FiscalClose::STATUS_ROLLED_FORWARD) {
            throw new InvalidArgumentException(__('Tier A purge requires a rolled-forward close (status: :status).', [
                'status' => $close->status,
            ]));
        }

        if ($this->tierACompleted($close)) {
            throw new InvalidArgumentException(__('Tier A ledger detail has already been purged.'));
        }

        if (blank($close->checksum) || $close->memberSnapshots()->count() === 0) {
            throw new InvalidArgumentException(__('A certified member snapshot is required before purge.'));
        }

        if (FiscalSettings::requiresExportBeforePurge() && ! $close->hasExports()) {
            throw new InvalidArgumentException(__('Generate archive exports before purging ledger detail.'));
        }
    }

    public function tierACompleted(FiscalClose $close): bool
    {
        return filled($close->purge_summary_json['tier_a']['completed_at'] ?? null);
    }

    public function tierBCompleted(FiscalClose $close): bool
    {
        return filled($close->purge_summary_json['tier_b']['completed_at'] ?? null);
    }

    public function purgeTransactionsThrough(Carbon $periodEnd): int
    {
        $deleted = 0;

        Transaction::query()
            ->where('transacted_at', '<=', $periodEnd)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($transactions) use (&$deleted): void {
                $ids = $transactions->pluck('id')->all();
                $deleted += Transaction::query()->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }

    public function purgeClearedBankLinesThrough(Carbon $periodEnd): int
    {
        $deleted = 0;

        BankTransaction::query()
            ->cleared()
            ->whereDate('transaction_date', '<=', $periodEnd->toDateString())
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($lines) use (&$deleted): void {
                $ids = $lines->pluck('id')->all();
                $deleted += BankTransaction::query()->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }

    public function purgeResolvedReconciliationExceptions(): int
    {
        return ReconciliationException::query()
            ->where('status', ReconciliationException::STATUS_RESOLVED)
            ->delete();
    }

    public function purgePostedContributionsThrough(Carbon $periodEnd): int
    {
        $deleted = 0;

        Contribution::query()
            ->whereDate('period', '<=', $periodEnd->toDateString())
            ->where('collection_status', ContributionCollectionStatus::COLLECTED)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($contributions) use (&$deleted): void {
                foreach ($contributions as $contribution) {
                    $contribution->forceDelete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    public function purgePaidLoanInstallmentsThrough(Carbon $periodEnd): int
    {
        $deleted = 0;

        LoanInstallment::query()
            ->where('status', 'paid')
            ->whereDate('due_date', '<=', $periodEnd->toDateString())
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($installments) use (&$deleted): void {
                foreach ($installments as $installment) {
                    $installment->forceDelete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    public function purgeClosedFundPostingsThrough(Carbon $periodEnd): int
    {
        return FundPosting::query()
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereDate('posting_date', '<=', $periodEnd->toDateString())
            ->delete();
    }

    public function purgeFundAuditLogThrough(Carbon $periodEnd): int
    {
        return FundAuditLog::query()
            ->where('occurred_at', '<=', $periodEnd)
            ->delete();
    }

    private function detachBankLinesFromLedger(Carbon $periodEnd): void
    {
        $transactionIds = Transaction::query()
            ->where('transacted_at', '<=', $periodEnd)
            ->pluck('id');

        if ($transactionIds->isEmpty()) {
            return;
        }

        BankTransaction::query()
            ->where(function ($query) use ($transactionIds): void {
                $query->whereIn('master_cash_transaction_id', $transactionIds)
                    ->orWhereIn('master_bank_transaction_id', $transactionIds)
                    ->orWhereIn('master_fund_transaction_id', $transactionIds);
            })
            ->update([
                'master_cash_transaction_id' => null,
                'master_bank_transaction_id' => null,
                'master_fund_transaction_id' => null,
            ]);
    }

    private function assertPostPurgeInvariants(FiscalClose $close): void
    {
        $master = $this->masterInvariants->check();

        if (! $master['balanced']) {
            throw new InvalidArgumentException(__(
                'Post-purge master pool invariant failed. Fund delta :fund, cash delta :cash.',
                [
                    'fund' => number_format($master['fund_delta'], 2),
                    'cash' => number_format($master['cash_delta'], 2),
                ],
            ));
        }

        $drifting = [];

        Member::query()
            ->active()
            ->with(['cashAccount', 'fundAccount'])
            ->orderBy('id')
            ->chunkById(100, function ($members) use (&$drifting): void {
                foreach ($members as $member) {
                    $result = $this->memberInvariants->check($member);

                    if (! $result['balanced']) {
                        $drifting[] = $member->id;
                    }
                }
            });

        if ($drifting !== []) {
            throw new InvalidArgumentException(__(
                'Post-purge member drift detected for :count member(s) after :label purge.',
                [
                    'count' => count($drifting),
                    'label' => $close->fiscal_year_label,
                ],
            ));
        }
    }
}
