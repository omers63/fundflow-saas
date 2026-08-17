<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Transaction;
use App\Services\Loans\LoanFreezeScheduleService;
use App\Services\Loans\LoanGuarantorTransferService;
use App\Services\Loans\LoanLedgerService;
use App\Support\AutomationSchedulerGate;
use App\Support\BusinessDayWindowRollbackEventInventory;
use App\Support\BusinessDayWindowRollbackReport;
use App\Support\ContributionCollectionStatus;
use App\Support\InstallmentCollectionStatus;
use App\Support\LoanRepaymentNote;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Undo scheduler collections and operational postings stamped after a setback business day.
 *
 * Reverses ledger by source, then resets contributions / EMIs / fees / deposits /
 * cash-outs / disbursements / manual journals / membership applications /
 * other leftover sources / early settlements / member withdrawals / freeze plans /
 * freeze ticks / overdue flags / statements / bank matches / bank-file postings,
 * discards post-as-of reconciliation snapshots and exceptions, and restores guarantor transfers.
 */
final class BusinessDayWindowRollbackService
{
    /**
     * @var array<string, BusinessDayWindowRollbackReport>
     */
    private array $previewCache = [];

    /**
     * @var list<class-string<Model>>
     */
    private const ALLOWED_REFERENCE_TYPES = [
        Contribution::class,
        LoanInstallment::class,
        BankTransaction::class,
        Transaction::class,
    ];

    /**
     * @var list<class-string<Model>>
     */
    private const REVERSIBLE_REFERENCE_TYPES = [
        FundPosting::class,
        CashOutRequest::class,
        ExpenseDisbursement::class,
        FeeDisbursement::class,
        InvestDisbursement::class,
        InvestReturn::class,
    ];

    public function __construct(
        private AccountingService $accounting,
        private ContributionCycleService $cycles,
        private LoanLedgerService $loanLedger,
        private LoanGuarantorTransferService $guarantorTransfers,
        private BankClearingMatchService $bankMatching,
        private FundAuditLogService $audit,
        private AutomationSchedulerGate $scheduler,
        private LoanFreezeScheduleService $freezeSchedules,
    ) {}

    public function preview(Carbon $asOf): BusinessDayWindowRollbackReport
    {
        @set_time_limit(0);

        $key = $asOf->copy()->startOfDay()->toDateString();

        return $this->previewCache[$key] ??= $this->run($asOf, dryRun: true);
    }

    /**
     * @param  list<string>|null  $selectedKeys  Null undoes every window event.
     */
    public function execute(Carbon $asOf, ?array $selectedKeys = null): BusinessDayWindowRollbackReport
    {
        @set_time_limit(0);

        $this->previewCache = [];

        $report = $this->run($asOf, dryRun: false, selectedKeys: $selectedKeys);

        if ($report->blocked) {
            throw new InvalidArgumentException($report->summary());
        }

        return $report;
    }

    /**
     * @param  list<string>|null  $selectedKeys
     */
    private function run(Carbon $asOf, bool $dryRun, ?array $selectedKeys = null): BusinessDayWindowRollbackReport
    {
        $asOf = $asOf->copy()->startOfDay();
        $cutoff = $asOf->copy()->endOfDay();
        $blockers = [];

        $contributions = $this->contributionsInWindow($cutoff);
        $installments = $this->installmentsInWindow($cutoff);
        $deposits = $this->depositsInWindow($cutoff);
        $cashOuts = $this->cashOutsInWindow($cutoff);
        $disbursements = $this->disbursementsInWindow($cutoff);
        $journals = $this->manualJournalsInWindow($cutoff, $deposits);
        $leftovers = $this->leftoverSourcesInWindow($cutoff);
        $applications = $leftovers->filter(fn (Model $source): bool => $source instanceof MembershipApplication)->values();
        $otherSources = $leftovers->reject(fn (Model $source): bool => $source instanceof MembershipApplication)->values();
        $earlySettlements = $this->earlySettlementsInWindow($cutoff);
        $withdrawals = $this->withdrawalsInWindow($cutoff);
        $transfers = $this->transfersInWindow($cutoff);
        $matches = $this->bankMatchesInWindow($cutoff);
        $statements = $this->statementsInWindow($asOf, $cutoff);
        $futureCycles = $this->futureCycleContributions($asOf);
        $windowFreezes = $this->freezesInWindow($cutoff);
        $freezeTicks = $this->freezeTickMembersInWindow($cutoff, $futureCycles, $windowFreezes);
        $overdueContributions = $this->overdueContributionsToReset($asOf);
        $overdueInstallments = $this->overdueInstallmentsToReset($asOf, $installments);
        $futureCyclesForTicks = $futureCycles;
        $freezeTickCounts = $freezeTicks
            ->mapWithKeys(fn (Member $member): array => [
                (int) $member->id => $this->freezeTickCountForMember($member, $futureCycles, $cutoff),
            ])
            ->all();

        if (! $dryRun && $selectedKeys !== null) {
            $want = array_flip($selectedKeys);
            $contributions = $this->onlySelected($contributions, 'contributions', $want);
            $installments = $this->onlySelected($installments, 'installments', $want);
            $deposits = $this->onlySelected($deposits, 'deposits', $want);
            $cashOuts = $this->onlySelected($cashOuts, 'cash-outs', $want);
            $disbursements = $this->onlySelected($disbursements, 'disbursements', $want);
            $journals = $this->onlySelected($journals, 'journals', $want);
            $applications = $this->onlySelected($applications, 'applications', $want);
            $otherSources = $this->onlySelected($otherSources, 'other-sources', $want);
            $earlySettlements = $this->onlySelected($earlySettlements, 'early-settlements', $want);
            $withdrawals = $this->onlySelected($withdrawals, 'withdrawals', $want);
            $windowFreezes = $this->onlySelected($windowFreezes, 'freezes', $want);
            $freezeTicks = $this->onlySelected($freezeTicks, 'freeze-ticks', $want);
            $transfers = $this->onlySelected($transfers, 'guarantor-transfers', $want);
            $matches = $this->onlySelected($matches, 'bank-matches', $want);
            $statements = $this->onlySelected($statements, 'statements', $want);
            $futureCycles = $this->onlySelected($futureCycles, 'future-cycles', $want);
            $overdueContributions = $overdueContributions
                ->filter(fn (Contribution $row): bool => isset($want[BusinessDayWindowRollbackEventInventory::overdueKey($row)]))
                ->values();
            $overdueInstallments = $overdueInstallments
                ->filter(fn (LoanInstallment $row): bool => isset($want[BusinessDayWindowRollbackEventInventory::overdueKey($row)]))
                ->values();
            $freezeTickCounts = $freezeTicks
                ->mapWithKeys(fn (Member $member): array => [
                    (int) $member->id => (int) ($freezeTickCounts[(int) $member->id] ?? 0),
                ])
                ->all();
        }

        $report = new BusinessDayWindowRollbackReport(
            asOf: $asOf,
            dryRun: $dryRun,
            blocked: $blockers !== [],
            blockers: $blockers,
            contributions: $contributions->count(),
            installments: $installments->count(),
            deposits: $deposits->count(),
            cashOuts: $cashOuts->count(),
            disbursements: $disbursements->count(),
            manualJournals: $journals->count(),
            applications: $applications->count(),
            otherSources: $otherSources->count(),
            earlySettlements: $earlySettlements->count(),
            withdrawals: $withdrawals->count(),
            freezes: $windowFreezes->count(),
            freezeTicks: $freezeTicks->sum(fn (Member $member): int => (int) ($freezeTickCounts[(int) $member->id] ?? 0)),
            guarantorTransfers: $transfers->count(),
            bankMatches: $matches->count(),
            statements: $statements->count(),
            futureCycleRows: $futureCycles->count(),
            overdueResets: $overdueContributions->count() + $overdueInstallments->count(),
            sections: (new BusinessDayWindowRollbackEventInventory)->sections(
                $contributions,
                $installments,
                $deposits,
                $cashOuts,
                $disbursements,
                $journals,
                $applications,
                $otherSources,
                $earlySettlements,
                $withdrawals,
                $windowFreezes,
                $freezeTicks,
                $transfers,
                $matches,
                $statements,
                $futureCycles,
                $overdueContributions,
                $overdueInstallments,
                $freezeTickCounts,
            ),
        );

        if ($blockers !== [] || $dryRun) {
            return $report;
        }

        $wasPaused = $this->scheduler->isPaused();
        $ownsPause = ! $wasPaused
            || $this->scheduler->reason() === __('Business day window rollback');

        if (! $wasPaused) {
            $this->scheduler->pause(__('Business day window rollback'));
        }

        try {
            $reversed = 0;

            DB::transaction(function () use ($asOf, $cutoff, $contributions, $installments, $deposits, $cashOuts, $disbursements, $journals, $applications, $otherSources, $earlySettlements, $withdrawals, $windowFreezes, $freezeTicks, $transfers, $matches, $statements, $futureCycles, $futureCyclesForTicks, $overdueContributions, $overdueInstallments, &$reversed): void {
                foreach ($matches as $line) {
                    $reversed += $this->reverseBankClearance($line, $cutoff);
                }

                $affectedLoanIds = [];

                foreach ($installments->sortByDesc(fn (LoanInstallment $row): string => (string) ($row->paid_at ?? $row->id)) as $installment) {
                    $reversed += $this->loanLedger->reverseInstallmentPosting(
                        $installment,
                        __('Business day window rollback'),
                    );
                    $affectedLoanIds[(int) $installment->loan_id] = true;
                    $this->resetInstallmentOpenState($installment->fresh(), $asOf);
                }

                foreach ($transfers as $loan) {
                    $this->guarantorTransfers->restoreToOriginalBorrower($loan->fresh());
                    $affectedLoanIds[(int) $loan->id] = true;
                }

                foreach ($contributions as $contribution) {
                    $reversed += $this->reverseContribution($contribution, $asOf);
                }

                foreach ($overdueContributions as $contribution) {
                    if ($contributions->contains(fn (Contribution $row): bool => (int) $row->id === (int) $contribution->id)) {
                        continue;
                    }

                    $this->resetContributionOpenState($contribution, $asOf);
                }

                foreach ($overdueInstallments as $installment) {
                    if ($installments->contains(fn (LoanInstallment $row): bool => (int) $row->id === (int) $installment->id)) {
                        continue;
                    }

                    $this->resetInstallmentOpenState($installment, $asOf);
                }

                foreach ($windowFreezes as $member) {
                    $this->unwindWindowFreeze($member->fresh() ?? $member, $asOf);
                }

                foreach ($freezeTicks as $member) {
                    $this->unwindFreezeTicks($member->fresh() ?? $member, $futureCyclesForTicks, $asOf, $cutoff);
                }

                foreach ($futureCycles as $contribution) {
                    $contribution->refresh();

                    if (
                        (float) ($contribution->amount_collected ?? 0) > 0.00001
                        || $contribution->status === 'posted'
                    ) {
                        $reversed += $this->reverseContribution($contribution, $asOf);
                    }

                    $contribution->delete();
                }

                foreach ($statements as $statement) {
                    $statement->delete();
                }

                foreach (array_keys($affectedLoanIds) as $loanId) {
                    $loan = Loan::query()->find($loanId);

                    if ($loan === null) {
                        continue;
                    }

                    $this->loanLedger->recomputeRepaidToMaster($loan);
                    $this->unwindEarlySettlement($loan, $cutoff);
                }

                foreach ($earlySettlements as $loan) {
                    if (isset($affectedLoanIds[(int) $loan->id])) {
                        continue;
                    }

                    $this->unwindEarlySettlement($loan->fresh(), $cutoff);
                }

                foreach ($cashOuts as $cashOut) {
                    $reversed += $this->reverseCashOut($cashOut);
                }

                foreach ($disbursements as $disbursement) {
                    $reversed += $this->reverseDisbursement($disbursement);
                }

                foreach ($deposits as $deposit) {
                    $reversed += $this->reverseDeposit($deposit, $cutoff);
                }

                foreach ($applications as $application) {
                    $reversed += $this->reverseLeftoverSource($application, $cutoff);
                }

                foreach ($otherSources as $source) {
                    $reversed += $this->reverseLeftoverSource($source, $cutoff);
                }

                foreach ($journals->sortBy(fn (Transaction $row): int => $row->type === 'debit' ? 0 : 1) as $journal) {
                    AccountingService::withoutMemberCashCollection(function () use ($journal, &$reversed): void {
                        $reversed += $this->reverseLedgerEntryWithoutPoolMirror($journal->fresh() ?? $journal);
                    });
                }

                foreach ($withdrawals as $member) {
                    $this->restoreWithdrawnMember($member->fresh() ?? $member, $asOf);
                }

                $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines();
            });

            $this->discardWindowReconciliation($cutoff);

            $report->ledgerLinesReversed = $reversed;

            $this->audit->log('BUSINESS_DAY_WINDOW_ROLLBACK', 'system', null, null, [
                'as_of' => $asOf->toDateString(),
                'contributions' => $report->contributions,
                'installments' => $report->installments,
                'deposits' => $report->deposits,
                'cash_outs' => $report->cashOuts,
                'disbursements' => $report->disbursements,
                'manual_journals' => $report->manualJournals,
                'applications' => $report->applications,
                'other_sources' => $report->otherSources,
                'early_settlements' => $report->earlySettlements,
                'withdrawals' => $report->withdrawals,
                'freezes' => $report->freezes,
                'freeze_ticks' => $report->freezeTicks,
                'guarantor_transfers' => $report->guarantorTransfers,
                'bank_matches' => $report->bankMatches,
                'statements' => $report->statements,
            ]);
        } finally {
            if ($ownsPause) {
                $this->scheduler->resume();
            }
        }

        return $report;
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @param  array<string, int|string>  $want
     * @return Collection<int, Model>
     */
    private function onlySelected(Collection $rows, string $type, array $want): Collection
    {
        return $rows
            ->filter(fn (Model $row): bool => isset($want[BusinessDayWindowRollbackEventInventory::modelKey($type, $row)]))
            ->values();
    }

    /**
     * @return Collection<int, Contribution>
     */
    private function contributionsInWindow(Carbon $cutoff): Collection
    {
        $idsFromLedger = Transaction::query()
            ->where('reference_type', Contribution::class)
            ->where('transacted_at', '>', $cutoff)
            ->pluck('reference_id');

        return Contribution::query()
            ->where(function ($query) use ($cutoff, $idsFromLedger): void {
                $query->where('posted_at', '>', $cutoff)
                    ->orWhere('paid_at', '>', $cutoff)
                    ->orWhereIn('id', $idsFromLedger);
            })
            ->get()
            ->filter(fn (Contribution $contribution): bool => $this->sourceNeedsWindowUndo(
                $contribution,
                $cutoff,
                $contribution->posted_at,
            ))
            ->values();
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    private function installmentsInWindow(Carbon $cutoff): Collection
    {
        $idsFromLedger = Transaction::query()
            ->where('reference_type', LoanInstallment::class)
            ->where('transacted_at', '>', $cutoff)
            ->pluck('reference_id');

        return LoanInstallment::query()
            ->where(function ($query) use ($cutoff, $idsFromLedger): void {
                $query->where('paid_at', '>', $cutoff)
                    ->orWhereIn('id', $idsFromLedger);
            })
            ->get()
            ->filter(fn (LoanInstallment $installment): bool => $this->sourceNeedsWindowUndo(
                $installment,
                $cutoff,
                $installment->paid_at,
            ))
            ->values();
    }

    /**
     * @return Collection<int, FundPosting>
     */
    private function depositsInWindow(Carbon $cutoff): Collection
    {
        return $this->referencedSourcesInWindow(FundPosting::class, $cutoff, 'reviewed_at');
    }

    /**
     * @return Collection<int, CashOutRequest>
     */
    private function cashOutsInWindow(Carbon $cutoff): Collection
    {
        return $this->referencedSourcesInWindow(CashOutRequest::class, $cutoff, 'reviewed_at');
    }

    /**
     * @return Collection<int, Model>
     */
    private function disbursementsInWindow(Carbon $cutoff): Collection
    {
        return $this->referencedSourcesInWindow(ExpenseDisbursement::class, $cutoff, 'transacted_at')
            ->concat($this->referencedSourcesInWindow(FeeDisbursement::class, $cutoff, 'transacted_at'))
            ->concat($this->referencedSourcesInWindow(InvestDisbursement::class, $cutoff, 'transacted_at'))
            ->concat($this->referencedSourcesInWindow(InvestReturn::class, $cutoff, 'transacted_at'))
            ->values();
    }

    /**
     * @param  class-string<Model>  $class
     * @return Collection<int, Model>
     */
    private function referencedSourcesInWindow(string $class, Carbon $cutoff, string $activityColumn): Collection
    {
        $idsFromLedger = Transaction::query()
            ->where('reference_type', $class)
            ->where('transacted_at', '>', $cutoff)
            ->pluck('reference_id');

        return $class::query()
            ->where(function ($query) use ($cutoff, $idsFromLedger, $activityColumn): void {
                $query->where($activityColumn, '>', $cutoff)
                    ->orWhereIn('id', $idsFromLedger);
            })
            ->get()
            ->filter(fn (Model $source): bool => $this->sourceNeedsWindowUndo(
                $source,
                $cutoff,
                $source->getAttribute($activityColumn),
            ))
            ->values();
    }

    /**
     * Remaining referenced sources in the window that are not collections or
     * already-handled operational types (applications, fund-outs, loans, etc.).
     *
     * @return Collection<int, Model>
     */
    private function leftoverSourcesInWindow(Carbon $cutoff): Collection
    {
        $pairs = Transaction::query()
            ->where('transacted_at', '>', $cutoff)
            ->whereNotNull('reference_type')
            ->whereNotNull('reference_id')
            ->whereNotIn('reference_type', [
                ...self::ALLOWED_REFERENCE_TYPES,
                ...self::REVERSIBLE_REFERENCE_TYPES,
            ])
            ->get(['reference_type', 'reference_id'])
            ->unique(fn (Transaction $row): string => $row->reference_type.'#'.$row->reference_id);

        return $pairs
            ->map(function (Transaction $row) use ($cutoff): ?Model {
                $class = (string) $row->reference_type;

                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    return null;
                }

                $source = $class::query()->find($row->reference_id);

                if ($source === null) {
                    return null;
                }

                return $this->sourceNeedsWindowUndo($source, $cutoff, null) ? $source : null;
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, Loan>
     */
    private function earlySettlementsInWindow(Carbon $cutoff): Collection
    {
        return Loan::query()
            ->where('status', 'early_settled')
            ->where('settled_at', '>', $cutoff)
            ->get();
    }

    /**
     * @return Collection<int, Member>
     */
    private function withdrawalsInWindow(Carbon $cutoff): Collection
    {
        return Member::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_withdrawn_at', '>', $cutoff)
                    ->orWhere('payout_frozen_at', '>', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->where('status', 'withdrawn')
                            ->where('status_changed_at', '>', $cutoff);
                    });
            })
            ->get();
    }

    /**
     * @return Collection<int, Member>
     */
    private function freezesInWindow(Carbon $cutoff): Collection
    {
        return Member::query()
            ->whereNotNull('frozen_at')
            ->where('frozen_at', '>', $cutoff)
            ->get();
    }

    /**
     * @param  Collection<int, Contribution>  $futureCycles
     * @param  Collection<int, Member>  $windowFreezes
     * @return Collection<int, Member>
     */
    private function freezeTickMembersInWindow(Carbon $cutoff, Collection $futureCycles, Collection $windowFreezes): Collection
    {
        $skip = $windowFreezes->modelKeys();

        return Member::query()
            ->whereNotNull('frozen_at')
            ->where('frozen_at', '<=', $cutoff)
            ->whereNotIn('id', $skip)
            ->where(function ($query): void {
                $query->where('freeze_emi_cycles_pushed', '>', 0)
                    ->orWhereNotNull('freeze_plan_ended_at');
            })
            ->get()
            ->filter(fn (Member $member): bool => $this->freezeTickCountForMember($member, $futureCycles, $cutoff) > 0)
            ->values();
    }

    /**
     * @param  Collection<int, Contribution>  $futureCycles
     */
    private function freezeTickCountForMember(Member $member, Collection $futureCycles, Carbon $cutoff): int
    {
        $ticks = $futureCycles
            ->filter(fn (Contribution $contribution): bool => $contribution->period !== null)
            ->unique(fn (Contribution $contribution): string => $contribution->period->format('Y-m'))
            ->filter(function (Contribution $contribution) use ($member): bool {
                $start = $this->cycles->cycleStartAt(
                    (int) $contribution->period->month,
                    (int) $contribution->period->year,
                );

                return $member->frozen_at !== null && $member->frozen_at->lt($start);
            })
            ->count();

        $ticks = max($ticks, $this->freezeTicksFromPushedBalance($member, $cutoff));

        if ($member->freeze_plan_ended_at !== null && $member->freeze_plan_ended_at->gt($cutoff)) {
            $ticks = max($ticks, 1);
        }

        return min($ticks, (int) ($member->freeze_emi_cycles_pushed ?? 0));
    }

    /**
     * Freeze ticks after as-of, even when later cycle contribution rows were already deleted.
     */
    private function freezeTicksFromPushedBalance(Member $member, Carbon $cutoff): int
    {
        if ($member->frozen_at === null || $member->frozen_at->gt($cutoff)) {
            return 0;
        }

        $pushed = (int) ($member->freeze_emi_cycles_pushed ?? 0);

        if ($pushed < 1) {
            return 0;
        }

        $endPre = $cutoff->copy();

        if ($member->freeze_plan_ended_at !== null && $member->freeze_plan_ended_at->lt($endPre)) {
            $endPre = $member->freeze_plan_ended_at->copy();
        }

        $pre = 1;
        $period = $member->frozen_at->copy()->startOfMonth();

        for ($i = 0; $i < 36; $i++) {
            $start = $this->cycles->cycleStartAt((int) $period->month, (int) $period->year);

            if ($start->gt($endPre)) {
                break;
            }

            if ($start->gt($member->frozen_at) && $start->lte($endPre)) {
                $pre++;
            }

            $period->addMonthNoOverflow();
        }

        return max(0, $pushed - $pre);
    }

    /**
     * @param  Collection<int, FundPosting>  $deposits
     * @return Collection<int, Transaction>
     */
    private function manualJournalsInWindow(Carbon $cutoff, Collection $deposits): Collection
    {
        $mirrorIds = $deposits
            ->flatMap(fn (FundPosting $posting): Collection => $this->unreferencedFundPostingMasterCashMirrors($posting))
            ->pluck('id');

        return Transaction::query()
            ->where('transacted_at', '>', $cutoff)
            ->whereNull('reference_type')
            ->whereNotIn('id', $mirrorIds)
            ->with('account')
            ->orderByRaw("CASE WHEN type = 'debit' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $transaction): bool => ! $this->accounting->hasExistingReversal($transaction))
            ->values();
    }

    /**
     * @return Collection<int, Loan>
     */
    private function transfersInWindow(Carbon $cutoff): Collection
    {
        return Loan::query()
            ->where('transferred_to_guarantor_at', '>', $cutoff)
            ->get();
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    private function bankMatchesInWindow(Carbon $cutoff): Collection
    {
        $idsFromLedger = Transaction::query()
            ->where('reference_type', BankTransaction::class)
            ->where(function ($query) use ($cutoff): void {
                $query->where('transacted_at', '>', $cutoff)
                    ->orWhere('created_at', '>', $cutoff);
            })
            ->pluck('reference_id');

        $matched = BankTransaction::query()
            ->with(['bankStatement', 'member'])
            ->where(function ($query) use ($cutoff, $idsFromLedger): void {
                $query->where(function ($cleared) use ($cutoff): void {
                    $cleared->where('is_cleared', true)
                        ->where('cleared_at', '>', $cutoff);
                })->orWhere(function ($posted) use ($cutoff): void {
                    $posted->whereIn('status', ['mirrored', 'posted'])
                        ->where('cleared_at', '>', $cutoff);
                })->orWhereIn('id', $idsFromLedger);
            })
            ->get()
            ->filter(function (BankTransaction $row): bool {
                if ($this->bankMatching->isSyntheticOperationalStatement($row)) {
                    return $row->is_cleared && ! $this->hasClearedImportedPartner($row);
                }

                return $row->is_cleared
                    || in_array($row->status, ['mirrored', 'posted'], true)
                    || $row->master_cash_transaction_id !== null
                    || $row->master_bank_transaction_id !== null;
            });

        $openFileLines = BankTransaction::query()
            ->with(['bankStatement', 'member'])
            ->whereIn('status', ['imported', 'mirrored'])
            ->whereDate('transaction_date', '>', $cutoff->toDateString())
            ->get()
            ->reject(fn (BankTransaction $row): bool => $this->bankMatching->isSyntheticOperationalStatement($row));

        return $matched
            ->concat($openFileLines)
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, MonthlyStatement>
     */
    private function statementsInWindow(Carbon $asOf, Carbon $cutoff): Collection
    {
        $periodCutoff = $asOf->format('Y-m');

        return MonthlyStatement::query()
            ->where(function ($query) use ($cutoff, $periodCutoff): void {
                $query->where('generated_at', '>', $cutoff)
                    ->orWhere('period', '>', $periodCutoff);
            })
            ->get();
    }

    /**
     * @return Collection<int, Contribution>
     */
    private function futureCycleContributions(Carbon $asOf): Collection
    {
        $cutoffStart = $asOf->copy()->endOfDay();

        return Contribution::query()
            ->whereDate('period', '>=', $asOf->copy()->startOfMonth()->toDateString())
            ->get()
            ->filter(function (Contribution $contribution) use ($cutoffStart): bool {
                if ($contribution->period === null) {
                    return false;
                }

                $start = $this->cycles->cycleStartAt(
                    (int) $contribution->period->month,
                    (int) $contribution->period->year,
                );

                return $start->gt($cutoffStart);
            })
            ->values();
    }

    /**
     * @return Collection<int, Contribution>
     */
    private function overdueContributionsToReset(Carbon $asOf): Collection
    {
        return Contribution::query()
            ->where('status', 'pending')
            ->whereIn('collection_status', [
                ContributionCollectionStatus::OVERDUE,
                ...ContributionCollectionStatus::lateStates(),
            ])
            ->get()
            ->filter(fn (Contribution $contribution): bool => $this->periodClosedAfterAsOf($contribution, $asOf))
            ->values();
    }

    /**
     * @param  Collection<int, LoanInstallment>  $alreadyReversing
     * @return Collection<int, LoanInstallment>
     */
    private function overdueInstallmentsToReset(Carbon $asOf, Collection $alreadyReversing): Collection
    {
        $skip = $alreadyReversing->pluck('id')->all();

        return LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereNotIn('id', $skip)
            ->get()
            ->filter(fn (LoanInstallment $installment): bool => $this->installmentClosedAfterAsOf($installment, $asOf))
            ->values();
    }

    private function reverseContribution(Contribution $contribution, Carbon $asOf): int
    {
        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($contribution, &$reversed): void {
            $reversed = $this->accounting->reverseAllSourceEntries(
                $contribution,
                __('Business day window rollback'),
            );
        });

        $this->resetContributionOpenState($contribution->fresh(), $asOf);

        return $reversed;
    }

    private function reverseCashOut(CashOutRequest $request): int
    {
        $this->unmatchImportedPartnersFor($request);

        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($request, &$reversed): void {
            $reversed = $this->reverseSourceWithoutPoolMirror($request);
        });

        $this->deleteRemittancesFor($request);

        $bankTransaction = $this->bankTransactionFor($request);

        $request->update([
            'status' => 'pending',
            'admin_remarks' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'bank_transaction_id' => null,
        ]);

        $this->removeLinkedBankTransaction($bankTransaction);

        return $reversed;
    }

    private function reverseDeposit(FundPosting $posting, Carbon $cutoff): int
    {
        $partnerIds = BankTransaction::query()
            ->where('fund_posting_id', $posting->id)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->unmatchImportedPartnersFor($posting);

        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($posting, &$reversed): void {
            $reversed = $this->reverseSourceWithoutPoolMirror($posting);

            foreach ($this->unreferencedFundPostingMasterCashMirrors($posting) as $mirror) {
                $reversed += $this->reverseLedgerEntryWithoutPoolMirror($mirror);
            }
        });

        $this->deleteRemittancesFor($posting);

        $bankTransaction = $this->bankTransactionFor($posting->fresh() ?? $posting);

        $posting->update([
            'status' => 'pending',
            'admin_remarks' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'bank_transaction_id' => null,
        ]);

        $this->removeLinkedBankTransaction($bankTransaction);

        foreach ($partnerIds as $partnerId) {
            $partner = BankTransaction::query()->find($partnerId);

            if ($partner instanceof BankTransaction) {
                $this->ignoreImportedBankLineAfterCutoff($partner, $cutoff);
            }
        }

        return $reversed;
    }

    private function reverseDisbursement(Model $disbursement): int
    {
        $this->unmatchImportedPartnersFor($disbursement);

        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($disbursement, &$reversed): void {
            $reversed = $this->reverseSourceWithoutPoolMirror($disbursement);
        });

        $this->deleteRemittancesFor($disbursement);

        $bankTransaction = $this->bankTransactionFor($disbursement);

        $disbursement->update(['bank_transaction_id' => null]);
        $this->removeLinkedBankTransaction($bankTransaction);
        $disbursement->delete();

        return $reversed;
    }

    private function reverseLeftoverSource(Model $source, Carbon $cutoff): int
    {
        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($source, $cutoff, &$reversed): void {
            $reversed = $this->reverseSourceWithoutPoolMirror($source, $cutoff);
        });

        $this->resetLeftoverSourceState($source, $cutoff);

        return $reversed;
    }

    private function resetLeftoverSourceState(Model $source, Carbon $cutoff): void
    {
        if ($source instanceof MembershipApplication) {
            $this->resetApplicationOpenState($source);

            return;
        }

        if ($source instanceof FundOutRequest || $source instanceof MemberCashTransferRequest) {
            $source->update([
                'status' => 'pending',
                'admin_remarks' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            return;
        }

        if ($source instanceof FeeDeduction || $source instanceof LoanRepayment) {
            $source->delete();

            return;
        }

        if ($source instanceof Loan) {
            $this->restoreLoanDisbursementIfFullyUnwound($source, $cutoff);
        }
    }

    private function resetApplicationOpenState(MembershipApplication $application): void
    {
        $this->deleteRemittancesFor($application);
        $this->unmatchImportedPartnersFor($application);

        $bankLines = BankTransaction::query()
            ->where('membership_application_id', $application->id)
            ->get();

        foreach ($bankLines as $bankLine) {
            $this->removeLinkedBankTransaction($bankLine);
        }

        $application->update([
            'status' => 'pending',
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);
    }

    private function restoreLoanDisbursementIfFullyUnwound(Loan $loan, Carbon $cutoff): void
    {
        $loan->refresh();

        $stillOpen = Transaction::query()
            ->where('reference_type', $loan::class)
            ->where('reference_id', $loan->id)
            ->with('account')
            ->get()
            ->contains(fn (Transaction $transaction): bool => ! $this->accounting->isReversalEntry($transaction)
                && ! $this->accounting->hasExistingReversal($transaction));

        if ($stillOpen || $loan->disbursed_at === null || ! $loan->disbursed_at->gt($cutoff)) {
            return;
        }

        LoanDisbursement::query()
            ->where('loan_id', $loan->id)
            ->where('disbursed_at', '>', $cutoff)
            ->delete();

        $loan->update([
            'status' => 'approved',
            'lifecycle_stage' => 'approved',
            'amount_disbursed' => 0,
            'disbursed_at' => null,
        ]);
    }

    private function reverseSourceWithoutPoolMirror(Model $source, ?Carbon $cutoff = null): int
    {
        $siblings = Transaction::query()
            ->where('reference_type', $source->getMorphClass())
            ->where('reference_id', $source->getKey())
            ->when($cutoff !== null, fn ($query) => $query->where('transacted_at', '>', $cutoff))
            ->with('account')
            ->orderByRaw("CASE WHEN type = 'debit' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $count = 0;

        foreach ($siblings as $entry) {
            $count += $this->reverseLedgerEntryWithoutPoolMirror($entry);
        }

        return $count;
    }

    private function reverseLedgerEntryWithoutPoolMirror(Transaction $entry): int
    {
        if ($this->accounting->isReversalEntry($entry) || $this->accounting->hasExistingReversal($entry)) {
            return 0;
        }

        $account = $entry->account;

        if ($account === null) {
            return 0;
        }

        $reason = __('Business day window rollback');

        if (! $account->is_master && in_array($account->type, ['cash', 'fund'], true)) {
            $amount = round((float) $entry->amount, 2);
            $description = __('Reversal of #:id: :original — :reason', [
                'id' => $entry->id,
                'original' => $entry->description ?? '—',
                'reason' => $reason,
            ]);

            if ($entry->type === 'credit') {
                $this->accounting->debit($account, $amount, $description, $entry, null, $entry->member_id);
            } else {
                $this->accounting->credit($account, $amount, $description, $entry, null, $entry->member_id);
            }

            return 1;
        }

        $this->accounting->createReversalEntry($entry, $reason);

        return 1;
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function unreferencedFundPostingMasterCashMirrors(FundPosting $posting): Collection
    {
        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            return collect();
        }

        $mirrorSuffix = __('(deposit mirror)');

        return Transaction::query()
            ->where('account_id', $masterCash->id)
            ->whereNull('reference_type')
            ->whereNull('reference_id')
            ->where('type', 'credit')
            ->where('amount', $posting->amount)
            ->whereDate('transacted_at', $posting->posting_date)
            ->where('description', 'like', '%'.$mirrorSuffix.'%')
            ->get();
    }

    private function deleteRemittancesFor(Model $source): void
    {
        InboundPayment::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();

        OutboundPayment::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }

    private function bankTransactionFor(Model $source): ?BankTransaction
    {
        $id = $source->getAttribute('bank_transaction_id');

        if ($id === null) {
            return null;
        }

        return BankTransaction::query()->find($id);
    }

    private function reverseBankClearance(BankTransaction $line, Carbon $cutoff): int
    {
        $line = $line->fresh() ?? $line;
        $reversed = 0;

        if ($this->isBankFilePosting($line)) {
            $reversed = $this->reverseBankFilePosting($line);
        } else {
            $this->unmatchIfCleared($line);
        }

        $this->ignoreImportedBankLineAfterCutoff($line->fresh() ?? $line, $cutoff);

        return $reversed;
    }

    private function ignoreImportedBankLineAfterCutoff(BankTransaction $line, Carbon $cutoff): void
    {
        $line = $line->fresh() ?? $line;

        if ($this->bankMatching->isSyntheticOperationalStatement($line)) {
            return;
        }

        if (! in_array($line->status, ['imported', 'mirrored'], true)) {
            return;
        }

        if ($line->transaction_date === null) {
            return;
        }

        if (! Carbon::parse($line->transaction_date)->startOfDay()->gt($cutoff->copy()->startOfDay())) {
            return;
        }

        $line->update([
            'status' => 'ignored',
            'is_cleared' => false,
            'cleared_at' => null,
        ]);
    }

    private function isBankFilePosting(BankTransaction $line): bool
    {
        if ($this->bankMatching->isSyntheticOperationalStatement($line)) {
            return false;
        }

        if ($this->operationalBankFkColumnFromLine($line) !== null) {
            return false;
        }

        return in_array($line->status, ['mirrored', 'posted'], true)
            || $line->master_cash_transaction_id !== null;
    }

    private function reverseBankFilePosting(BankTransaction $line): int
    {
        $reversed = 0;

        AccountingService::withoutMemberCashCollection(function () use ($line, &$reversed): void {
            $reversed = $this->reverseSourceWithoutPoolMirror($line);
        });

        $line->update([
            'status' => 'imported',
            'is_cleared' => false,
            'cleared_at' => null,
            'member_id' => null,
            'master_cash_transaction_id' => null,
            'master_bank_transaction_id' => null,
            'master_fund_transaction_id' => null,
        ]);

        return $reversed;
    }

    private function unmatchImportedPartnersFor(Model $source): void
    {
        $column = $this->operationalBankFkColumn($source);

        if ($column === null) {
            return;
        }

        BankTransaction::query()
            ->with('bankStatement')
            ->where($column, $source->getKey())
            ->where('is_cleared', true)
            ->get()
            ->each(function (BankTransaction $line): void {
                if ($this->bankMatching->isSyntheticOperationalStatement($line)) {
                    return;
                }

                $this->unmatchIfCleared($line);
            });
    }

    private function unmatchIfCleared(BankTransaction $line): void
    {
        $line = $line->fresh() ?? $line;

        if (! $line->is_cleared) {
            return;
        }

        if ($this->bankMatching->isSyntheticOperationalStatement($line)) {
            $line->update([
                'is_cleared' => false,
                'cleared_at' => null,
            ]);

            return;
        }

        $this->bankMatching->unmatchClearedPair($line);
    }

    private function operationalBankFkColumn(Model $source): ?string
    {
        return match (true) {
            $source instanceof FundPosting => 'fund_posting_id',
            $source instanceof CashOutRequest => 'cash_out_request_id',
            $source instanceof ExpenseDisbursement => 'expense_disbursement_id',
            $source instanceof FeeDisbursement => 'fee_disbursement_id',
            $source instanceof InvestDisbursement => 'invest_disbursement_id',
            $source instanceof InvestReturn => 'invest_return_id',
            $source instanceof MembershipApplication => 'membership_application_id',
            default => null,
        };
    }

    private function hasClearedImportedPartner(BankTransaction $line): bool
    {
        $column = $this->operationalBankFkColumnFromLine($line);

        if ($column === null) {
            return false;
        }

        return BankTransaction::query()
            ->with('bankStatement')
            ->where('id', '!=', $line->id)
            ->where('is_cleared', true)
            ->where($column, $line->getAttribute($column))
            ->get()
            ->contains(fn (BankTransaction $candidate): bool => ! $this->bankMatching->isSyntheticOperationalStatement($candidate));
    }

    private function operationalBankFkColumnFromLine(BankTransaction $line): ?string
    {
        return match (true) {
            $line->fund_posting_id !== null => 'fund_posting_id',
            $line->cash_out_request_id !== null => 'cash_out_request_id',
            $line->expense_disbursement_id !== null => 'expense_disbursement_id',
            $line->fee_disbursement_id !== null => 'fee_disbursement_id',
            $line->invest_disbursement_id !== null => 'invest_disbursement_id',
            $line->invest_return_id !== null => 'invest_return_id',
            $line->membership_application_id !== null => 'membership_application_id',
            default => null,
        };
    }

    private function discardWindowReconciliation(Carbon $cutoff): void
    {
        ReconciliationSnapshot::query()->where('as_of', '>', $cutoff)->delete();
        ReconciliationException::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('raised_at', '>', $cutoff)
                    ->orWhere('status', ReconciliationException::STATUS_OPEN);
            })
            ->delete();
    }

    private function removeLinkedBankTransaction(?BankTransaction $bankTransaction): void
    {
        if ($bankTransaction === null) {
            return;
        }

        $bankTransaction = $bankTransaction->fresh() ?? $bankTransaction;

        if (! $this->bankMatching->isSyntheticOperationalStatement($bankTransaction)) {
            $this->unmatchIfCleared($bankTransaction);

            return;
        }

        $bankTransaction->forceFill([
            'fund_posting_id' => null,
            'cash_out_request_id' => null,
            'expense_disbursement_id' => null,
            'fee_disbursement_id' => null,
            'invest_disbursement_id' => null,
            'invest_return_id' => null,
            'membership_application_id' => null,
        ])->saveQuietly();

        $bankTransaction->delete();
    }

    private function resetContributionOpenState(Contribution $contribution, Carbon $asOf): void
    {
        $closedAfter = $this->periodClosedAfterAsOf($contribution, $asOf);

        $contribution->update([
            'status' => 'pending',
            'collection_status' => $closedAfter
                ? ContributionCollectionStatus::PENDING
                : ContributionCollectionStatus::OVERDUE,
            'amount_collected' => 0,
            'posted_at' => null,
            'paid_at' => null,
            'late_fee_amount' => null,
            'late_fee_tier' => null,
            'is_late' => ! $closedAfter,
            'overdue_since' => $closedAfter ? null : $this->periodDueEnd($contribution),
        ]);
    }

    private function resetInstallmentOpenState(LoanInstallment $installment, Carbon $asOf): void
    {
        if (! in_array($installment->status, ['pending', 'overdue'], true)) {
            return;
        }

        $closedAfter = $this->installmentClosedAfterAsOf($installment, $asOf);

        $installment->update([
            'status' => $closedAfter ? 'pending' : 'overdue',
            'collection_status' => $closedAfter
                ? InstallmentCollectionStatus::PENDING
                : InstallmentCollectionStatus::OVERDUE,
            'is_late' => ! $closedAfter,
            'overdue_since' => $closedAfter ? null : $this->installmentPeriodDueEnd($installment),
        ]);
    }

    private function periodClosedAfterAsOf(Contribution $contribution, Carbon $asOf): bool
    {
        if ($contribution->period === null) {
            return true;
        }

        $dueEnd = $this->cycles->cycleDueEndAt(
            (int) $contribution->period->month,
            (int) $contribution->period->year,
        );

        return $dueEnd->copy()->addDay()->startOfDay()->gt($asOf->copy()->startOfDay());
    }

    private function installmentClosedAfterAsOf(LoanInstallment $installment, Carbon $asOf): bool
    {
        if ($installment->due_date === null) {
            return true;
        }

        $due = Carbon::parse($installment->due_date);
        $dueEnd = $this->cycles->cycleDueEndAt((int) $due->month, (int) $due->year);

        return $dueEnd->copy()->addDay()->startOfDay()->gt($asOf->copy()->startOfDay());
    }

    private function periodDueEnd(Contribution $contribution): ?Carbon
    {
        if ($contribution->period === null) {
            return null;
        }

        return $this->cycles->cycleDueEndAt(
            (int) $contribution->period->month,
            (int) $contribution->period->year,
        );
    }

    private function installmentPeriodDueEnd(LoanInstallment $installment): ?Carbon
    {
        if ($installment->due_date === null) {
            return null;
        }

        $due = Carbon::parse($installment->due_date);

        return $this->cycles->cycleDueEndAt((int) $due->month, (int) $due->year);
    }

    private function sourceNeedsWindowUndo(Model $source, Carbon $cutoff, mixed $activityAt): bool
    {
        if ($activityAt !== null && Carbon::parse($activityAt)->gt($cutoff)) {
            return true;
        }

        return Transaction::query()
            ->where('reference_type', $source::class)
            ->where('reference_id', $source->getKey())
            ->where('transacted_at', '>', $cutoff)
            ->get()
            ->contains(function (Transaction $transaction): bool {
                return ! $this->accounting->isReversalEntry($transaction)
                    && ! $this->accounting->hasExistingReversal($transaction);
            });
    }

    private function restoreActiveIfWindowSettled(Loan $loan, Carbon $cutoff): void
    {
        $loan->refresh();

        $unpaid = $loan->installments()->whereIn('status', ['pending', 'overdue'])->exists();

        if (! $unpaid) {
            return;
        }

        $updates = [];

        if (in_array($loan->status, ['completed', 'early_settled'], true)) {
            $updates['status'] = 'active';
            $updates['lifecycle_stage'] = 'active';
        }

        if ($loan->completed_at !== null && $loan->completed_at->gt($cutoff)) {
            $updates['completed_at'] = null;
        }

        if ($loan->settled_at !== null && $loan->settled_at->gt($cutoff)) {
            $updates['settled_at'] = null;
        }

        if ($updates !== []) {
            $loan->update($updates);
        }
    }

    private function unwindEarlySettlement(Loan $loan, Carbon $cutoff): void
    {
        LoanRepayment::query()
            ->where('loan_id', $loan->id)
            ->where('paid_at', '>', $cutoff)
            ->get()
            ->filter(fn (LoanRepayment $row): bool => LoanRepaymentNote::isSettlement($row->notes))
            ->each(fn (LoanRepayment $row) => $row->delete());

        $this->restoreActiveIfWindowSettled($loan, $cutoff);
    }

    private function restoreWithdrawnMember(Member $member, Carbon $asOf): void
    {
        if ($member->status !== 'withdrawn' && $member->payout_frozen_at === null && $member->last_withdrawn_at === null) {
            return;
        }

        $member->update([
            'status' => 'active',
            'status_reason' => null,
            'status_changed_at' => $asOf->copy()->endOfDay(),
            'contribution_cycles_active' => true,
            'payout_frozen_at' => null,
            'last_withdrawn_at' => null,
            'reinstated_at' => null,
        ]);
    }

    private function unwindWindowFreeze(Member $member, Carbon $asOf): void
    {
        if ($member->frozen_at === null) {
            return;
        }

        $pushed = (int) ($member->freeze_emi_cycles_pushed ?? 0);

        if ($pushed > 0) {
            $this->freezeSchedules->pullCyclesForMember($member, $pushed);
        }

        $this->resetMemberInstallmentOpenState($member, $asOf);

        $member->update([
            'status' => 'active',
            'status_reason' => null,
            'status_changed_at' => $asOf->copy()->endOfDay(),
            'contribution_cycles_active' => true,
            'frozen_at' => null,
            'freeze_cycles_requested' => null,
            'freeze_cycles_remaining' => null,
            'freeze_emi_cycles_pushed' => null,
            'freeze_plan_ended_at' => null,
            'freeze_household_mode' => null,
            'freeze_temporary_parent_member_id' => null,
            'freeze_origin_member_id' => null,
        ]);
    }

    /**
     * @param  Collection<int, Contribution>  $futureCycles
     */
    private function unwindFreezeTicks(Member $member, Collection $futureCycles, Carbon $asOf, Carbon $cutoff): void
    {
        $ticks = $this->freezeTickCountForMember($member, $futureCycles, $cutoff);

        if ($ticks < 1) {
            return;
        }

        $this->freezeSchedules->pullCyclesForMember($member, $ticks);
        $this->resetMemberInstallmentOpenState($member, $asOf);

        $pushed = max(0, (int) ($member->freeze_emi_cycles_pushed ?? 0) - $ticks);
        $indefinite = (int) ($member->freeze_cycles_requested ?? -1) === MemberFreezeService::INDEFINITE_CYCLES;
        $remaining = $indefinite
            ? (int) ($member->freeze_cycles_remaining ?? 0)
            : (int) ($member->freeze_cycles_remaining ?? 0) + $ticks;

        $member->update([
            'freeze_emi_cycles_pushed' => $pushed,
            'freeze_cycles_remaining' => $remaining,
            'freeze_plan_ended_at' => $remaining > 0 || $indefinite ? null : $member->freeze_plan_ended_at,
        ]);
    }

    private function resetMemberInstallmentOpenState(Member $member, Carbon $asOf): void
    {
        $loanIds = Loan::query()->where('member_id', $member->id)->pluck('id');

        LoanInstallment::query()
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', ['pending', 'overdue'])
            ->get()
            ->each(fn (LoanInstallment $installment) => $this->resetInstallmentOpenState($installment, $asOf));
    }
}
