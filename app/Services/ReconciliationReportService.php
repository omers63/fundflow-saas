<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\Loans\LoanLedgerService;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Financial reconciliation: ledger integrity, control totals, pipeline hygiene,
 * bank statement vs book (optional), contribution vs fund ledger, and loan checks
 * (active + approved partial disbursement).
 */
class ReconciliationReportService
{
    public const AMOUNT_TOLERANCE = 0.03;

    public const LOAN_SCHEDULE_TOLERANCE = 1.0;

    /**
     * Optional inputs from UI or {@see Setting} keys for scheduled runs:
     * - declared_bank_balance (float): statement / bank closing balance to compare to master_cash book.
     * - declared_bank_date (string|null): informational (Y-m-d).
     * - bank_mismatch_treat_as_critical (bool): if true, book vs stated variance is critical; else warning.
     *
     * @return array{
     *   meta: array,
     *   verdict: array,
     *   checks: array,
     *   coverage_matrix: list<array{flow: string, checks: list<array{key: string, severity: string}>}>,
     *   pipeline: array,
     *   period_metrics: array,
     *   summary: array
     * }
     */
    public function buildReport(
        string $mode,
        ?CarbonInterface $asOf = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        array $options = [],
    ): array {
        @set_time_limit(0);

        $asOf = $asOf ? Carbon::parse($asOf) : now();

        $declaredBank = isset($options['declared_bank_balance']) && $options['declared_bank_balance'] !== null && $options['declared_bank_balance'] !== ''
            ? (float) $options['declared_bank_balance']
            : null;
        $declaredBankDate = isset($options['declared_bank_date']) ? (string) $options['declared_bank_date'] : null;
        $bankMismatchCritical = (bool) ($options['bank_mismatch_treat_as_critical'] ?? false);

        $meta = [
            'mode' => $mode,
            'as_of' => $asOf->toIso8601String(),
            'period_start' => $periodStart?->toIso8601String(),
            'period_end' => $periodEnd?->toIso8601String(),
            'timezone' => config('app.timezone'),
            'options' => array_filter([
                'declared_bank_balance' => $declaredBank,
                'declared_bank_date' => $declaredBankDate,
                'bank_mismatch_treat_as_critical' => $bankMismatchCritical,
            ], fn ($v) => $v !== null && $v !== '' && $v !== false),
        ];

        $checks = [];
        $critical = 0;
        $warnings = 0;

        $incrementCritical = function () use (&$critical): void {
            $critical++;
        };
        $incrementWarning = function () use (&$warnings): void {
            $warnings++;
        };

        // --- 1) Per-account ledger vs stored balance ---
        $ledgerRows = Transaction::query()
            ->selectRaw('account_id, SUM(CASE WHEN type = ? THEN amount ELSE -amount END) as computed', ['credit'])
            ->groupBy('account_id');

        $computedByAccount = DB::query()
            ->fromSub($ledgerRows, 't')
            ->pluck('computed', 'account_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        $ledgerMismatches = [];
        $accounts = Account::query()->get();
        foreach ($accounts as $account) {
            $computed = (float) ($computedByAccount[$account->id] ?? 0.0);
            $stored = (float) $account->balance;
            $delta = abs($computed - $stored);
            if ($delta > self::AMOUNT_TOLERANCE) {
                $ledgerMismatches[] = [
                    'account_id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'member_id' => $account->member_id,
                    'loan_id' => $account->loan_id,
                    'stored_balance' => round($stored, 2),
                    'computed_from_ledger' => round($computed, 2),
                    'delta' => round($computed - $stored, 2),
                ];
            }
        }

        if ($ledgerMismatches !== []) {
            $incrementCritical();
        }

        $checks['ledger_balances'] = [
            'label' => 'Stored balance vs ledger roll-forward',
            'severity' => $ledgerMismatches === [] ? 'ok' : 'critical',
            'accounts_checked' => $accounts->count(),
            'mismatch_count' => count($ledgerMismatches),
            'mismatches' => array_slice($ledgerMismatches, 0, 200),
            'mismatches_truncated' => count($ledgerMismatches) > 200,
        ];

        // --- 2) Global trial ---
        $totals = Transaction::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as credits, COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as debits',
                ['credit', 'debit']
            )
            ->first();

        $sumCredits = (float) ($totals->credits ?? 0);
        $sumDebits = (float) ($totals->debits ?? 0);
        $trialDelta = abs($sumCredits - $sumDebits);
        $trialOk = $trialDelta <= self::AMOUNT_TOLERANCE;
        if (! $trialOk) {
            $incrementWarning();
        }

        $checks['global_trial'] = array_merge([
            'label' => 'Global posting trial (Σ credits vs Σ debits)',
            'severity' => $trialOk ? 'ok' : 'warning',
            'sum_credits' => round($sumCredits, 2),
            'sum_debits' => round($sumDebits, 2),
            'delta' => round($sumCredits - $sumDebits, 2),
            'note' => 'In a strictly paired ledger these should match. Drift may indicate one-sided manual entries, imports, or reversals.',
        ], $trialOk ? [] : $this->buildGlobalTrialDiagnostics());

        // --- 3) Master vs Σ(member) pool mirrors (same formula as MasterAccountInvariantService) ---
        $masterCash = Account::masterCash();
        $masterFund = Account::masterFund();

        if ($masterCash === null) {
            $incrementCritical();
        }
        if ($masterFund === null) {
            $incrementCritical();
        }

        $pool = app(MasterAccountInvariantService::class)->check();
        $tolerance = ContributionPolicySettings::reconTolerance();
        $cashDeltaAbs = $pool['cash_delta'];
        $fundDeltaAbs = $pool['fund_delta'];
        $suspenseBalance = abs($pool['master_suspense_balance']);

        $balanced = $cashDeltaAbs <= $tolerance
            && $fundDeltaAbs <= $tolerance
            && $suspenseBalance <= $tolerance;

        if (! $balanced) {
            $incrementWarning();
        }

        $checks['paired_control_totals'] = [
            'label' => 'Master control vs aggregate member mirrors',
            'severity' => $balanced ? 'ok' : 'warning',
            'master_cash_balance' => round($pool['master_cash'], 2),
            'sum_member_cash' => round($pool['member_cash_sum'], 2),
            'cash_delta' => round($pool['master_cash'] - $pool['member_cash_sum'], 2),
            'cash_delta_abs' => $cashDeltaAbs,
            'master_fund_balance' => round($pool['master_fund'], 2),
            'master_fund_pool' => round($pool['master_fund_pool'], 2),
            'sum_member_fund' => round($pool['member_fund_sum'], 2),
            'fund_delta' => round($pool['master_fund_pool'] - $pool['member_fund_sum'], 2),
            'fund_delta_abs' => $fundDeltaAbs,
            'master_invest_balance' => round($pool['master_invest_balance'], 2),
            'master_expense_balance' => round($pool['master_expense_balance'], 2),
            'master_fees_balance' => round($pool['master_fees_balance'], 2),
            'master_suspense_balance' => round($pool['master_suspense_balance'], 2),
            'master_bank_balance' => round($pool['master_bank_balance'], 2),
            'master_invest_from_fund_credits' => round($pool['master_invest_from_fund_credits'], 2),
            'master_expense_from_fund_credits' => round($pool['master_expense_from_fund_credits'], 2),
            'master_invest_return_to_fund_credits' => round($pool['master_invest_return_to_fund_credits'], 2),
            'tolerance' => $tolerance,
            'note' => __('Fund comparison uses pool-adjusted master fund: ledger fund balance minus invest returns credited to fund, plus invest/expense reserve funding from fund. Fees, bank, and suspense are control/reserve accounts (not member mirrors); suspense should be near zero after rounding.'),
        ];

        // --- 3b) Bank statement vs master_cash book (optional) ---
        if ($declaredBank !== null && $masterCash !== null) {
            $book = round((float) $masterCash->balance, 2);
            $stated = round($declaredBank, 2);
            $variance = round($book - $stated, 2);
            $match = abs($variance) <= self::AMOUNT_TOLERANCE;
            if (! $match) {
                if ($bankMismatchCritical) {
                    $incrementCritical();
                } else {
                    $incrementWarning();
                }
            }
            $checks['bank_statement_vs_book'] = [
                'label' => 'Master cash (book) vs declared bank / statement balance',
                'severity' => $match ? 'ok' : ($bankMismatchCritical ? 'critical' : 'warning'),
                'master_cash_book' => $book,
                'declared_balance' => $stated,
                'declared_bank_date' => $declaredBankDate,
                'variance_book_minus_stated' => $variance,
                'note' => 'Set optional fields when running from the UI, or Setting keys reconciliation.bank_statement_balance and reconciliation.bank_statement_date for scheduled runs.',
            ];
        } else {
            $checks['bank_statement_vs_book'] = [
                'label' => 'Master cash (book) vs declared bank / statement balance',
                'severity' => 'skipped',
                'note' => 'No declared statement balance supplied.',
            ];
        }

        // --- 3c) Contributions vs master fund ledger ---
        $contribMorph = Contribution::class;
        $missingLedgerContributions = [];
        if ($masterFund !== null) {
            Contribution::query()
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($contribMorph, &$missingLedgerContributions): void {
                    foreach ($rows as $row) {
                        if (! $row instanceof Contribution) {
                            continue;
                        }
                        $exists = Transaction::query()
                            ->where('reference_type', $contribMorph)
                            ->where('reference_id', $row->id)
                            ->exists();
                        if (! $exists) {
                            $missingLedgerContributions[] = [
                                'contribution_id' => $row->id,
                                'member_id' => $row->member_id,
                                'amount' => (float) $row->amount,
                                'period' => $row->period?->format('Y-m'),
                            ];
                        }
                    }
                });

            $ledgerContribMaster = (float) Transaction::query()
                ->where('account_id', $masterFund->id)
                ->where('reference_type', $contribMorph)
                ->where('type', 'credit')
                ->sum('amount');

            $contribSum = (float) Contribution::query()->whereNull('deleted_at')->sum('amount');
            $masterDelta = abs($ledgerContribMaster - $contribSum);
            $masterMatch = $masterDelta <= self::AMOUNT_TOLERANCE;

            if ($missingLedgerContributions !== []) {
                $incrementCritical();
            }
            if (! $masterMatch) {
                $incrementWarning();
            }

            $checks['contributions_ledger'] = [
                'label' => 'Contributions — ledger presence and master fund credits',
                'severity' => $missingLedgerContributions === [] && $masterMatch ? 'ok' : ($missingLedgerContributions !== [] ? 'critical' : 'warning'),
                'missing_ledger_count' => count($missingLedgerContributions),
                'missing_ledger_sample' => array_slice($missingLedgerContributions, 0, 50),
                'sum_contribution_rows' => round($contribSum, 2),
                'sum_master_fund_credits_sourced_contribution' => round($ledgerContribMaster, 2),
                'master_fund_delta' => round($ledgerContribMaster - $contribSum, 2),
                'note' => 'Each contribution should post paired master+member fund credits; missing lines indicate failed posting or data repair needs.',
            ];
        } else {
            $checks['contributions_ledger'] = [
                'label' => 'Contributions — ledger presence and master fund credits',
                'severity' => 'skipped',
                'note' => 'Master fund account missing.',
            ];
        }

        // --- 3d) Member portal deposit accept integrity (FundPosting → cash mirror) ---
        $memberPortalPostingIssues = [];
        $memberPortalPostedCount = 0;
        $fundPostingMorph = FundPosting::class;
        $masterCashId = $masterCash?->id;

        FundPosting::query()
            ->where('status', 'accepted')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$memberPortalPostingIssues, &$memberPortalPostedCount, $fundPostingMorph, $masterCashId): void {
                foreach ($rows as $posting) {
                    if (! $posting instanceof FundPosting) {
                        continue;
                    }

                    $memberPortalPostedCount++;

                    if ($posting->member_id === null) {
                        $memberPortalPostingIssues[] = [
                            'fund_posting_id' => $posting->id,
                            'issue' => 'accepted fund posting has no member_id',
                        ];

                        continue;
                    }

                    $lines = Transaction::query()
                        ->where('reference_type', $fundPostingMorph)
                        ->where('reference_id', $posting->id)
                        ->get();

                    $masterLine = $masterCashId
                        ? $lines->first(fn (Transaction $line) => (int) $line->account_id === (int) $masterCashId)
                        : null;

                    if ($masterLine === null) {
                        $memberPortalPostingIssues[] = [
                            'fund_posting_id' => $posting->id,
                            'issue' => 'missing master cash ledger line for accepted fund posting',
                        ];
                    } elseif ($masterLine->type !== 'credit' || abs((float) $masterLine->amount - (float) $posting->amount) > self::AMOUNT_TOLERANCE) {
                        $memberPortalPostingIssues[] = [
                            'fund_posting_id' => $posting->id,
                            'issue' => 'master cash ledger line does not match posting amount/type',
                            'ledger_amount' => (float) $masterLine->amount,
                            'posting_amount' => (float) $posting->amount,
                            'ledger_type' => $masterLine->type,
                        ];
                    }

                    $memberCashLineExists = Transaction::query()
                        ->where('reference_type', $fundPostingMorph)
                        ->where('reference_id', $posting->id)
                        ->where('type', 'credit')
                        ->where('member_id', $posting->member_id)
                        ->whereHas('account', fn ($query) => $query->where('type', 'cash')->where('is_master', false)->where('member_id', $posting->member_id))
                        ->exists();

                    if (! $memberCashLineExists) {
                        $memberPortalPostingIssues[] = [
                            'fund_posting_id' => $posting->id,
                            'issue' => 'missing member cash mirror line for accepted fund posting',
                            'member_id' => $posting->member_id,
                        ];
                    }
                }
            });

        if ($memberPortalPostingIssues !== []) {
            $incrementCritical();
        }

        $checks['member_portal_posting_integrity'] = [
            'label' => 'Member portal deposits — accepted posting + cash mirror integrity',
            'severity' => $memberPortalPostingIssues === [] ? 'ok' : 'critical',
            'transactions_checked' => $memberPortalPostedCount,
            'issue_count' => count($memberPortalPostingIssues),
            'issues' => array_slice($memberPortalPostingIssues, 0, 100),
            'issues_truncated' => count($memberPortalPostingIssues) > 100,
        ];

        // --- 3e) Bank transaction mirror / clearance ledger integrity ---
        $bankPostingIssues = [];
        $bankPostedCount = 0;

        BankTransaction::query()
            ->whereIn('status', ['mirrored', 'posted'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$bankPostingIssues, &$bankPostedCount): void {
                foreach ($rows as $tx) {
                    if (! $tx instanceof BankTransaction) {
                        continue;
                    }

                    $bankPostedCount++;
                    $ledger = $tx->resolveMasterCashTransaction(false);
                    $expectedType = $tx->isCredit() ? 'credit' : 'debit';
                    $expectedAmount = abs((float) $tx->amount);

                    if ($ledger === null) {
                        $bankPostingIssues[] = [
                            'bank_transaction_id' => $tx->id,
                            'issue' => 'missing master cash ledger line for mirrored/posted bank row',
                        ];

                        continue;
                    }

                    if ($ledger->type !== $expectedType || abs((float) $ledger->amount - $expectedAmount) > self::AMOUNT_TOLERANCE) {
                        $bankPostingIssues[] = [
                            'bank_transaction_id' => $tx->id,
                            'issue' => 'master cash ledger line amount/type mismatch',
                            'ledger_type' => $ledger->type,
                            'ledger_amount' => (float) $ledger->amount,
                            'tx_amount' => $expectedAmount,
                            'tx_direction' => $expectedType,
                        ];
                    }

                    if ($tx->fund_posting_id !== null && $tx->member_id !== null) {
                        $memberCashLine = Transaction::query()
                            ->where('reference_type', FundPosting::class)
                            ->where('reference_id', $tx->fund_posting_id)
                            ->where('member_id', $tx->member_id)
                            ->whereHas('account', fn ($query) => $query->where('type', 'cash')->where('is_master', false)->where('member_id', $tx->member_id))
                            ->first();

                        if ($memberCashLine === null) {
                            $bankPostingIssues[] = [
                                'bank_transaction_id' => $tx->id,
                                'issue' => 'cleared deposit bank row missing member cash mirror via fund posting',
                                'member_id' => $tx->member_id,
                            ];
                        }
                    }
                }
            });

        if ($bankPostingIssues !== []) {
            $incrementCritical();
        }

        $checks['bank_transaction_posting_integrity'] = [
            'label' => 'Bank transactions — mirror/clearance ledger legs integrity',
            'severity' => $bankPostingIssues === [] ? 'ok' : 'critical',
            'transactions_checked' => $bankPostedCount,
            'issue_count' => count($bankPostingIssues),
            'issues' => array_slice($bankPostingIssues, 0, 120),
            'issues_truncated' => count($bankPostingIssues) > 120,
        ];

        // --- 3f) SMS transaction posting integrity (not used in SaaS) ---
        $checks['sms_transaction_posting_integrity'] = [
            'label' => 'SMS transactions — posted ledger legs integrity',
            'severity' => 'skipped',
            'note' => 'SMS bank import is not available in this SaaS deployment; use bank statement import instead.',
        ];

        // --- 4) Active loans: installment schedule vs loan ledger ---
        $activeLoanMismatches = [];
        Loan::query()
            ->where('status', 'active')
            ->with(['member.user', 'installments'])
            ->chunkById(100, function ($loans) use (&$activeLoanMismatches): void {
                foreach ($loans as $loan) {
                    if (! $loan instanceof Loan) {
                        continue;
                    }
                    $acc = $loan->account();
                    if (! $acc) {
                        continue;
                    }
                    $ledgerOutstanding = max(0.0, -(float) $acc->balance);
                    $metrics = $this->loanOutstandingReconciliationMetrics($loan, $ledgerOutstanding);
                    if (abs($metrics['delta']) > self::LOAN_SCHEDULE_TOLERANCE) {
                        $activeLoanMismatches[] = [
                            'loan_id' => $loan->id,
                            'phase' => 'active',
                            'member' => $loan->member?->user?->name,
                            'ledger_outstanding' => $metrics['ledger_account_outstanding'],
                            'ledger_expected' => $metrics['ledger_expected'],
                            'scheduled_outstanding' => $metrics['scheduled_outstanding'],
                            'partial_paid_ahead' => $metrics['partial_paid_ahead'],
                            'schedule_remaining' => $metrics['scheduled_outstanding'],
                            'delta' => $metrics['delta'],
                        ];
                    }
                }
            });

        if ($activeLoanMismatches !== []) {
            $incrementWarning();
        }

        $checks['active_loans_schedule_vs_ledger'] = [
            'label' => 'Active loans — loan ledger vs expected outstanding (scheduled − partial paid)',
            'severity' => $activeLoanMismatches === [] ? 'ok' : 'warning',
            'mismatch_count' => count($activeLoanMismatches),
            'mismatches' => array_slice($activeLoanMismatches, 0, 100),
            'mismatches_truncated' => count($activeLoanMismatches) > 100,
            'note' => 'Loan account outstanding is compared to the master-slice ledger (scheduled pending EMIs minus partial repayments posted ahead of the schedule).',
        ];

        // --- 4b) Approved loans with disbursement(s): ledger vs disbursed / schedule ---
        $approvedLoanMismatches = [];
        Loan::query()
            ->where('status', 'approved')
            ->where('amount_disbursed', '>', 0)
            ->with(['member.user', 'installments'])
            ->chunkById(100, function ($loans) use (&$approvedLoanMismatches): void {
                foreach ($loans as $loan) {
                    if (! $loan instanceof Loan) {
                        continue;
                    }
                    $acc = $loan->account();
                    if (! $acc) {
                        continue;
                    }
                    $ledgerOutstanding = max(0.0, -(float) $acc->balance);
                    $hasInstallments = $loan->installments()->exists();
                    if ($hasInstallments) {
                        $metrics = $this->loanOutstandingReconciliationMetrics($loan, $ledgerOutstanding);
                        $expected = $metrics['ledger_expected'];
                    } else {
                        $expected = (float) $loan->amount_disbursed;
                        $metrics = null;
                    }
                    if (abs($ledgerOutstanding - $expected) > self::LOAN_SCHEDULE_TOLERANCE) {
                        $approvedLoanMismatches[] = array_filter([
                            'loan_id' => $loan->id,
                            'phase' => 'approved',
                            'member' => $loan->member?->user?->name,
                            'ledger_outstanding' => $metrics['ledger_account_outstanding'] ?? round($ledgerOutstanding, 2),
                            'expected_outstanding' => round($expected, 2),
                            'expected_basis' => $hasInstallments ? 'ledger_outstanding' : 'amount_disbursed',
                            'scheduled_outstanding' => $metrics['scheduled_outstanding'] ?? null,
                            'partial_paid_ahead' => $metrics['partial_paid_ahead'] ?? null,
                            'amount_disbursed' => round((float) $loan->amount_disbursed, 2),
                            'delta' => round($ledgerOutstanding - $expected, 2),
                        ], fn (mixed $value): bool => $value !== null);
                    }
                }
            });

        if ($approvedLoanMismatches !== []) {
            $incrementWarning();
        }

        $checks['approved_loans_disbursement_vs_ledger'] = [
            'label' => 'Approved loans (with disbursement) — ledger vs disbursed / schedule',
            'severity' => $approvedLoanMismatches === [] ? 'ok' : 'warning',
            'mismatch_count' => count($approvedLoanMismatches),
            'mismatches' => array_slice($approvedLoanMismatches, 0, 100),
            'mismatches_truncated' => count($approvedLoanMismatches) > 100,
            'note' => 'Before installments exist, loan account outstanding should match amount_disbursed; once installments exist, compare to ledger expected outstanding (scheduled pending EMIs minus partial repayments ahead of the schedule).',
        ];

        // --- 4c) Loan disbursement cash payout leg integrity ---
        $loanCashPayoutMismatches = [];
        Loan::query()
            ->whereIn('status', ['approved', 'active', 'completed', 'early_settled'])
            ->where('amount_disbursed', '>', 0)
            ->with(['member.user'])
            ->chunkById(100, function ($loans) use (&$loanCashPayoutMismatches): void {
                foreach ($loans as $loan) {
                    if (! $loan instanceof Loan || ! $loan->member_id) {
                        continue;
                    }

                    $memberCashCredits = (float) Transaction::query()
                        ->where('reference_type', Loan::class)
                        ->where('reference_id', $loan->id)
                        ->where('type', 'credit')
                        ->where('member_id', $loan->member_id)
                        ->whereHas('account', fn ($q) => $q
                            ->where('type', 'cash')
                            ->where('member_id', $loan->member_id))
                        ->tap(fn ($query) => LoanLedgerService::excludeExcessFundToCashCredits($query))
                        ->sum('amount');

                    $expected = (float) $loan->amount_disbursed;
                    if (abs($memberCashCredits - $expected) > self::AMOUNT_TOLERANCE) {
                        $loanCashPayoutMismatches[] = [
                            'loan_id' => $loan->id,
                            'member' => $loan->member?->user?->name,
                            'status' => $loan->status,
                            'amount_disbursed' => round($expected, 2),
                            'member_cash_credits_from_loan' => round($memberCashCredits, 2),
                            'delta' => round($memberCashCredits - $expected, 2),
                        ];
                    }
                }
            });

        if ($loanCashPayoutMismatches !== []) {
            $incrementCritical();
        }

        $checks['loan_disbursement_cash_payout_integrity'] = [
            'label' => 'Loan disbursements — member cash payout credit leg present',
            'severity' => $loanCashPayoutMismatches === [] ? 'ok' : 'critical',
            'mismatch_count' => count($loanCashPayoutMismatches),
            'mismatches' => array_slice($loanCashPayoutMismatches, 0, 100),
            'mismatches_truncated' => count($loanCashPayoutMismatches) > 100,
            'note' => 'Expected member cash payout credits (disbursement legs) sourced from Loan should equal loans.amount_disbursed. Split-strategy excess fund-to-cash transfers are excluded.',
        ];

        // --- 4d) Contribution posting flow integrity (all expected legs by type) ---
        $contributionFlowIssues = [];
        Contribution::query()
            ->whereNull('deleted_at')
            ->with('member')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$contributionFlowIssues, $masterFund, $masterCash): void {
                foreach ($rows as $contribution) {
                    if (! $contribution instanceof Contribution) {
                        continue;
                    }

                    $memberId = (int) $contribution->member_id;
                    $amount = (float) $contribution->amount;
                    $lateFee = (float) ($contribution->late_fee_amount ?? 0);
                    $contribMorph = Contribution::class;

                    $masterFundCredits = (float) Transaction::query()
                        ->where('reference_type', $contribMorph)
                        ->where('reference_id', $contribution->id)
                        ->where('type', 'credit')
                        ->where('account_id', $masterFund?->id)
                        ->sum('amount');

                    if ($masterFund === null || abs($masterFundCredits - $amount) > self::AMOUNT_TOLERANCE) {
                        $contributionFlowIssues[] = [
                            'contribution_id' => $contribution->id,
                            'issue' => 'master fund credit leg missing/mismatch',
                            'expected' => round($amount, 2),
                            'actual' => round($masterFundCredits, 2),
                        ];
                    }

                    $memberFundCredits = (float) Transaction::query()
                        ->where('reference_type', $contribMorph)
                        ->where('reference_id', $contribution->id)
                        ->where('type', 'credit')
                        ->where('member_id', $memberId)
                        ->whereHas('account', fn ($q) => $q
                            ->where('type', 'fund')
                            ->where('member_id', $memberId))
                        ->sum('amount');

                    if (abs($memberFundCredits - $amount) > self::AMOUNT_TOLERANCE) {
                        $contributionFlowIssues[] = [
                            'contribution_id' => $contribution->id,
                            'issue' => 'member fund credit leg missing/mismatch',
                            'expected' => round($amount, 2),
                            'actual' => round($memberFundCredits, 2),
                            'member_id' => $memberId,
                        ];
                    }

                    if ((string) $contribution->payment_method === Contribution::PAYMENT_METHOD_CASH_ACCOUNT) {
                        $expectedCashDebit = $amount + max(0.0, $lateFee);
                        $memberCashDebits = (float) Transaction::query()
                            ->where('reference_type', $contribMorph)
                            ->where('reference_id', $contribution->id)
                            ->where('type', 'debit')
                            ->where('member_id', $memberId)
                            ->whereHas('account', fn ($q) => $q
                                ->where('type', 'cash')
                                ->where('member_id', $memberId))
                            ->sum('amount');

                        if (abs($memberCashDebits - $expectedCashDebit) > self::AMOUNT_TOLERANCE) {
                            $contributionFlowIssues[] = [
                                'contribution_id' => $contribution->id,
                                'issue' => 'member cash debit leg missing/mismatch for cash_account contribution',
                                'expected' => round($expectedCashDebit, 2),
                                'actual' => round($memberCashDebits, 2),
                                'member_id' => $memberId,
                            ];
                        }
                    }

                    if ($contribution->is_late && $lateFee > self::AMOUNT_TOLERANCE) {
                        $masterCashCredits = (float) Transaction::query()
                            ->where('reference_type', $contribMorph)
                            ->where('reference_id', $contribution->id)
                            ->where('type', 'credit')
                            ->where('account_id', $masterCash?->id)
                            ->sum('amount');

                        if ($masterCash === null || abs($masterCashCredits - $lateFee) > self::AMOUNT_TOLERANCE) {
                            $contributionFlowIssues[] = [
                                'contribution_id' => $contribution->id,
                                'issue' => 'late-fee master cash credit missing/mismatch',
                                'expected' => round($lateFee, 2),
                                'actual' => round($masterCashCredits, 2),
                            ];
                        }
                    }
                }
            });

        if ($contributionFlowIssues !== []) {
            $incrementCritical();
        }

        $checks['contribution_flow_integrity'] = [
            'label' => 'Contributions — full posting legs integrity by payment type',
            'severity' => $contributionFlowIssues === [] ? 'ok' : 'critical',
            'issue_count' => count($contributionFlowIssues),
            'issues' => array_slice($contributionFlowIssues, 0, 120),
            'issues_truncated' => count($contributionFlowIssues) > 120,
        ];

        // --- 4e) Membership application subscription fee posting integrity ---
        $membershipFeeIssues = [];
        $masterFees = Account::masterFees();

        MembershipApplication::query()
            ->where('status', 'approved')
            ->where(function ($query): void {
                $query->where('membership_fee_amount', '>', 0)
                    ->orWhere('membership_fee_required_amount', '>', 0);
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$membershipFeeIssues, $masterCash, $masterFees): void {
                foreach ($rows as $application) {
                    if (! $application instanceof MembershipApplication) {
                        continue;
                    }

                    $expectedTransfer = (float) ($application->membership_fee_amount ?? 0);
                    $expectedFee = min($expectedTransfer, (float) ($application->membership_fee_required_amount ?? $expectedTransfer));

                    if ($expectedTransfer > self::AMOUNT_TOLERANCE) {
                        $actualTransfer = (float) Transaction::query()
                            ->where('reference_type', MembershipApplication::class)
                            ->where('reference_id', $application->id)
                            ->where('type', 'credit')
                            ->where('account_id', $masterCash?->id)
                            ->where('description', 'like', '%(subscription deposit mirror)%')
                            ->sum('amount');

                        if ($masterCash === null || abs($actualTransfer - $expectedTransfer) > self::AMOUNT_TOLERANCE) {
                            $membershipFeeIssues[] = [
                                'membership_application_id' => $application->id,
                                'issue' => 'master cash subscription deposit mirror missing/mismatch',
                                'expected' => round($expectedTransfer, 2),
                                'actual' => round($actualTransfer, 2),
                            ];
                        }
                    }

                    if ($expectedFee > self::AMOUNT_TOLERANCE) {
                        $actualFee = (float) Transaction::query()
                            ->where('reference_type', MembershipApplication::class)
                            ->where('reference_id', $application->id)
                            ->where('type', 'credit')
                            ->where('account_id', $masterFees?->id)
                            ->sum('amount');

                        if ($masterFees === null || abs($actualFee - $expectedFee) > self::AMOUNT_TOLERANCE) {
                            $membershipFeeIssues[] = [
                                'membership_application_id' => $application->id,
                                'issue' => 'master fees subscription-fee credit missing/mismatch',
                                'expected' => round($expectedFee, 2),
                                'actual' => round($actualFee, 2),
                            ];
                        }
                    }
                }
            });

        if ($membershipFeeIssues !== []) {
            $incrementCritical();
        }

        $checks['membership_application_fee_integrity'] = [
            'label' => 'Membership application subscription fees — deposit and fee legs integrity',
            'severity' => $membershipFeeIssues === [] ? 'ok' : 'critical',
            'issue_count' => count($membershipFeeIssues),
            'issues' => array_slice($membershipFeeIssues, 0, 100),
            'issues_truncated' => count($membershipFeeIssues) > 100,
        ];

        // --- 4f) Annual subscription fee posting integrity ---
        $subscriptionFeeIssues = [];
        FeeDeduction::query()
            ->where('amount', '>', 0)
            ->where('description', 'like', '%subscription fee%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$subscriptionFeeIssues, $masterFees): void {
                foreach ($rows as $fee) {
                    if (! $fee instanceof FeeDeduction) {
                        continue;
                    }

                    $expected = (float) $fee->amount;
                    $actual = (float) Transaction::query()
                        ->where('reference_type', FeeDeduction::class)
                        ->where('reference_id', $fee->id)
                        ->where('type', 'credit')
                        ->where('account_id', $masterFees?->id)
                        ->sum('amount');

                    if ($masterFees === null || abs($actual - $expected) > self::AMOUNT_TOLERANCE) {
                        $subscriptionFeeIssues[] = [
                            'fee_deduction_id' => $fee->id,
                            'issue' => 'master fees annual subscription credit missing/mismatch',
                            'expected' => round($expected, 2),
                            'actual' => round($actual, 2),
                        ];
                    }
                }
            });

        if ($subscriptionFeeIssues !== []) {
            $incrementCritical();
        }

        $checks['subscription_fee_integrity'] = [
            'label' => 'Annual subscription fees — master fees credit integrity',
            'severity' => $subscriptionFeeIssues === [] ? 'ok' : 'critical',
            'issue_count' => count($subscriptionFeeIssues),
            'issues' => array_slice($subscriptionFeeIssues, 0, 100),
            'issues_truncated' => count($subscriptionFeeIssues) > 100,
        ];

        // --- 4g) Loan installment posting integrity (repayment + borrower/guarantor legs) ---
        $loanInstallmentFlowIssues = [];
        $masterFundId = $masterFund?->id;
        $masterCashId = $masterCash?->id;
        $legacyImportedLoanIds = array_fill_keys($this->legacyImportedLoanIds(), true);

        LoanInstallment::query()
            ->whereNull('deleted_at')
            ->where(function ($q): void {
                $q->where('status', 'paid')->orWhere('paid_by_guarantor', true);
            })
            ->with('loan')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$loanInstallmentFlowIssues, $masterFundId, $masterCashId, $legacyImportedLoanIds): void {
                foreach ($rows as $installment) {
                    if (! $installment instanceof LoanInstallment || ! $installment->loan) {
                        continue;
                    }

                    if (isset($legacyImportedLoanIds[(int) $installment->loan_id])) {
                        continue;
                    }

                    $sourceType = LoanInstallment::class;
                    $sourceId = (int) $installment->id;
                    $amount = (float) $installment->amount;
                    $lateFee = (float) ($installment->late_fee_amount ?? 0);
                    $borrowerId = (int) ($installment->loan->member_id ?? 0);
                    $guarantorId = (int) ($installment->loan->guarantor_member_id ?? 0);

                    $masterFundCredits = (float) Transaction::query()
                        ->where('reference_type', $sourceType)
                        ->where('reference_id', $sourceId)
                        ->where('type', 'credit')
                        ->where('account_id', $masterFundId)
                        ->sum('amount');
                    if ($masterFundId === null || abs($masterFundCredits - $amount) > self::AMOUNT_TOLERANCE) {
                        $loanInstallmentFlowIssues[] = [
                            'installment_id' => $sourceId,
                            'loan_id' => $installment->loan_id,
                            'issue' => 'master fund credit leg missing/mismatch',
                            'expected' => round($amount, 2),
                            'actual' => round($masterFundCredits, 2),
                        ];
                    }

                    $memberFundCredits = (float) Transaction::query()
                        ->where('reference_type', $sourceType)
                        ->where('reference_id', $sourceId)
                        ->where('type', 'credit')
                        ->where('member_id', $borrowerId)
                        ->whereHas('account', fn ($q) => $q
                            ->where('type', 'fund')
                            ->where('member_id', $borrowerId))
                        ->sum('amount');
                    if (abs($memberFundCredits - $amount) > self::AMOUNT_TOLERANCE) {
                        $loanInstallmentFlowIssues[] = [
                            'installment_id' => $sourceId,
                            'loan_id' => $installment->loan_id,
                            'issue' => 'borrower member fund credit leg missing/mismatch',
                            'expected' => round($amount, 2),
                            'actual' => round($memberFundCredits, 2),
                            'member_id' => $borrowerId,
                        ];
                    }

                    $loanAccountCredits = (float) Transaction::query()
                        ->where('reference_type', $sourceType)
                        ->where('reference_id', $sourceId)
                        ->where('type', 'credit')
                        ->whereHas('account', fn ($q) => $q
                            ->where('type', 'loan')
                            ->where('loan_id', $installment->loan_id))
                        ->sum('amount');
                    if (abs($loanAccountCredits - $amount) > self::AMOUNT_TOLERANCE) {
                        $loanInstallmentFlowIssues[] = [
                            'installment_id' => $sourceId,
                            'loan_id' => $installment->loan_id,
                            'issue' => 'loan account credit leg missing/mismatch',
                            'expected' => round($amount, 2),
                            'actual' => round($loanAccountCredits, 2),
                        ];
                    }

                    if ($installment->is_late && $lateFee > self::AMOUNT_TOLERANCE) {
                        $lateFeeMasterCashCredits = (float) Transaction::query()
                            ->where('reference_type', $sourceType)
                            ->where('reference_id', $sourceId)
                            ->where('type', 'credit')
                            ->where('account_id', $masterCashId)
                            ->sum('amount');

                        if ($masterCashId === null || abs($lateFeeMasterCashCredits - $lateFee) > self::AMOUNT_TOLERANCE) {
                            $loanInstallmentFlowIssues[] = [
                                'installment_id' => $sourceId,
                                'loan_id' => $installment->loan_id,
                                'issue' => 'late-fee master cash credit missing/mismatch',
                                'expected' => round($lateFee, 2),
                                'actual' => round($lateFeeMasterCashCredits, 2),
                            ];
                        }
                    }

                    if ((bool) $installment->paid_by_guarantor) {
                        $guarantorFundDebits = (float) Transaction::query()
                            ->where('reference_type', $sourceType)
                            ->where('reference_id', $sourceId)
                            ->where('type', 'debit')
                            ->where('member_id', $guarantorId)
                            ->whereHas('account', fn ($q) => $q
                                ->where('type', 'fund')
                                ->where('member_id', $guarantorId))
                            ->sum('amount');

                        if ($guarantorId <= 0 || abs($guarantorFundDebits - $amount) > self::AMOUNT_TOLERANCE) {
                            $loanInstallmentFlowIssues[] = [
                                'installment_id' => $sourceId,
                                'loan_id' => $installment->loan_id,
                                'issue' => 'guarantor fund debit missing/mismatch',
                                'expected' => round($amount, 2),
                                'actual' => round($guarantorFundDebits, 2),
                                'guarantor_member_id' => $guarantorId ?: null,
                            ];
                        }
                    } else {
                        $expectedCashDebit = $amount + max(0.0, $lateFee);
                        $borrowerCashDebits = (float) Transaction::query()
                            ->where('reference_type', $sourceType)
                            ->where('reference_id', $sourceId)
                            ->where('type', 'debit')
                            ->where('member_id', $borrowerId)
                            ->whereHas('account', fn ($q) => $q
                                ->where('type', 'cash')
                                ->where('member_id', $borrowerId))
                            ->sum('amount');

                        if (abs($borrowerCashDebits - $expectedCashDebit) > self::AMOUNT_TOLERANCE) {
                            $loanInstallmentFlowIssues[] = [
                                'installment_id' => $sourceId,
                                'loan_id' => $installment->loan_id,
                                'issue' => 'borrower cash debit missing/mismatch',
                                'expected' => round($expectedCashDebit, 2),
                                'actual' => round($borrowerCashDebits, 2),
                                'member_id' => $borrowerId,
                            ];
                        }
                    }
                }
            });

        $legacyImportedLoanIssues = $this->legacyImportedLoanRepaymentFlowIssues(
            array_keys($legacyImportedLoanIds),
            $masterFundId,
        );
        $loanInstallmentFlowIssues = array_merge($loanInstallmentFlowIssues, $legacyImportedLoanIssues);

        if ($loanInstallmentFlowIssues !== []) {
            $incrementCritical();
        }

        $checks['loan_installment_flow_integrity'] = [
            'label' => 'Loan installments — repayment and cash/guarantor legs integrity',
            'severity' => $loanInstallmentFlowIssues === [] ? 'ok' : 'critical',
            'issue_count' => count($loanInstallmentFlowIssues),
            'issues' => array_slice($loanInstallmentFlowIssues, 0, 120),
            'issues_truncated' => count($loanInstallmentFlowIssues) > 120,
            'legacy_import_loan_count' => count($legacyImportedLoanIds),
            'note' => count($legacyImportedLoanIds) > 0
                ? __('Legacy-imported loans validate repayment totals at loan level (LoanRepayment references) rather than per-installment ledger legs.')
                : null,
        ];

        // --- 4h) Member cash transfer integrity (member-sourced transfer rows) ---
        $memberTransferIssues = [];
        $memberTransferGroupRows = Transaction::query()
            ->where('reference_type', Member::class)
            ->whereHas('account', fn ($query) => $query->where('type', 'cash')->where('is_master', false))
            ->where(function ($q): void {
                $q->where('description', 'like', 'Transfer to % cash account%')
                    ->orWhere('description', 'like', 'Transfer from % cash account%');
            })
            ->get(['id', 'amount', 'type', 'transacted_at']);

        $groupedTransfers = $memberTransferGroupRows->groupBy(function ($row): string {
            $ts = optional($row->transacted_at)?->format('Y-m-d H:i:s') ?? 'na';

            return $ts.'|'.number_format((float) $row->amount, 2, '.', '');
        });

        foreach ($groupedTransfers as $groupKey => $rows) {
            $creditSum = (float) $rows->where('type', 'credit')->sum('amount');
            $debitSum = (float) $rows->where('type', 'debit')->sum('amount');

            if (
                abs($creditSum - $debitSum) > self::AMOUNT_TOLERANCE
                || $rows->where('type', 'credit')->count() === 0
                || $rows->where('type', 'debit')->count() === 0
            ) {
                $memberTransferIssues[] = [
                    'group' => $groupKey,
                    'rows' => $rows->pluck('id')->all(),
                    'credit_sum' => round($creditSum, 2),
                    'debit_sum' => round($debitSum, 2),
                    'row_count' => $rows->count(),
                    'issue' => 'cash transfer group does not net to zero with both debit and credit legs',
                ];
            }
        }

        if ($memberTransferIssues !== []) {
            $incrementCritical();
        }

        $checks['member_cash_transfer_integrity'] = [
            'label' => 'Member cash transfers — paired debit/credit integrity',
            'severity' => $memberTransferIssues === [] ? 'ok' : 'critical',
            'group_count' => $groupedTransfers->count(),
            'issue_count' => count($memberTransferIssues),
            'issues' => array_slice($memberTransferIssues, 0, 80),
            'issues_truncated' => count($memberTransferIssues) > 80,
            'note' => 'Groups by timestamp+amount for transfer descriptions and expects equal debit/credit totals.',
        ];

        // --- 5) Orphan loan accounts ---
        $orphanLoanAccounts = Account::query()
            ->where('type', 'loan')
            ->whereNotNull('loan_id')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw('1'))
                    ->from('loans')
                    ->whereColumn('loans.id', 'accounts.loan_id');
            })
            ->get(['id', 'loan_id', 'name', 'balance'])
            ->map(fn (Account $a) => [
                'account_id' => $a->id,
                'loan_id' => $a->loan_id,
                'name' => $a->name,
                'balance' => (float) $a->balance,
            ])
            ->all();

        if ($orphanLoanAccounts !== []) {
            $incrementCritical();
        }

        $checks['orphan_loan_accounts'] = [
            'label' => 'Loan-type accounts whose loan row is missing',
            'severity' => $orphanLoanAccounts === [] ? 'ok' : 'critical',
            'count' => count($orphanLoanAccounts),
            'accounts' => $orphanLoanAccounts,
        ];

        // --- 6) Pipeline ---
        $bankClearing = app(BankClearingMatchService::class);

        $bankImported = $bankClearing
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->where('status', 'imported')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as amt')
            ->first();

        $bankUncleared = BankTransaction::query()
            ->where('is_cleared', false)
            ->where('status', '!=', 'duplicate')
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount), 0) as amt')
            ->first();

        $pipeline = [
            'bank_unposted_count' => (int) ($bankImported->c ?? 0),
            'bank_unposted_amount' => round((float) ($bankImported->amt ?? 0), 2),
            'bank_uncleared_count' => (int) ($bankUncleared->c ?? 0),
            'bank_uncleared_amount' => round((float) ($bankUncleared->amt ?? 0), 2),
            'sms_unposted_count' => 0,
            'sms_unposted_amount' => 0.0,
            'note' => 'Unposted counts real bank CSV imports (status=imported) excluding synthetic operational clearance statements. Uncleared uses is_cleared=false. SMS import is not available.',
        ];

        $pipelineHasBacklog = $pipeline['bank_unposted_count'] > 0 || $pipeline['bank_uncleared_count'] > 0;

        if ($pipelineHasBacklog) {
            $incrementWarning();
        }

        $checks['bank_pipeline'] = [
            'label' => 'Bank import & clearance pipeline',
            'severity' => $pipelineHasBacklog ? 'warning' : 'ok',
            'bank_unposted_count' => $pipeline['bank_unposted_count'],
            'bank_unposted_amount' => $pipeline['bank_unposted_amount'],
            'bank_uncleared_count' => $pipeline['bank_uncleared_count'],
            'bank_uncleared_amount' => $pipeline['bank_uncleared_amount'],
            'issue_count' => $pipeline['bank_unposted_count'] + $pipeline['bank_uncleared_count'],
            'note' => $pipeline['note'],
        ];

        // --- 7) Period metrics ---
        $periodMetrics = [
            'ledger_lines_in_period' => null,
            'bank_mirrored_in_period' => null,
        ];

        if ($periodStart && $periodEnd) {
            $pStart = Carbon::parse($periodStart)->startOfDay();
            $pEnd = Carbon::parse($periodEnd)->endOfDay();

            $periodMetrics['ledger_lines_in_period'] = (int) Transaction::query()
                ->whereBetween('transacted_at', [$pStart, $pEnd])
                ->count();

            $periodMetrics['bank_mirrored_in_period'] = (int) BankTransaction::query()
                ->whereIn('status', ['mirrored', 'posted'])
                ->whereBetween('transaction_date', [$pStart->toDateString(), $pEnd->toDateString()])
                ->count();
        }

        $openExceptions = ReconciliationException::query()->open()->get();
        $controlLayer = [
            'open_exception_count' => $openExceptions->count(),
            'open_by_domain' => $openExceptions->groupBy('domain')->map->count()->all(),
            'open_by_code' => $openExceptions->groupBy('code')->map->count()->all(),
            'note' => 'Operational control layer from fund:nightly-reconciliation. Snapshots preserve audit history; exceptions are refreshed each nightly batch.',
        ];

        $verdict = [
            'pass' => $critical === 0,
            'critical_issues' => $critical,
            'warnings' => $warnings,
        ];

        $summary = [
            'verdict' => $verdict,
            'headline_checks' => [
                'ledger_balances' => $checks['ledger_balances']['severity'],
                'global_trial' => $checks['global_trial']['severity'],
                'bank_statement_vs_book' => $checks['bank_statement_vs_book']['severity'],
                'contributions_ledger' => $checks['contributions_ledger']['severity'],
                'contribution_flow_integrity' => $checks['contribution_flow_integrity']['severity'],
                'membership_application_fee_integrity' => $checks['membership_application_fee_integrity']['severity'],
                'subscription_fee_integrity' => $checks['subscription_fee_integrity']['severity'],
                'member_portal_posting_integrity' => $checks['member_portal_posting_integrity']['severity'],
                'bank_transaction_posting_integrity' => $checks['bank_transaction_posting_integrity']['severity'],
                'bank_pipeline' => $checks['bank_pipeline']['severity'],
                'sms_transaction_posting_integrity' => $checks['sms_transaction_posting_integrity']['severity'],
                'loan_installment_flow_integrity' => $checks['loan_installment_flow_integrity']['severity'],
                'member_cash_transfer_integrity' => $checks['member_cash_transfer_integrity']['severity'],
                'orphan_loan_accounts' => $checks['orphan_loan_accounts']['severity'],
                'paired_control_totals' => $checks['paired_control_totals']['severity'],
                'active_loans' => $checks['active_loans_schedule_vs_ledger']['severity'],
                'approved_loans' => $checks['approved_loans_disbursement_vs_ledger']['severity'],
                'loan_disbursement_cash_payout_integrity' => $checks['loan_disbursement_cash_payout_integrity']['severity'],
            ],
            'pipeline' => $pipeline,
            'control_layer' => $controlLayer,
            'as_of' => $meta['as_of'],
        ];

        $checkSeverity = static function (string $key) use ($checks): string {
            return (string) ($checks[$key]['severity'] ?? 'unknown');
        };
        $covRow = static function (string $flow, array $keys) use ($checkSeverity): array {
            return [
                'flow' => $flow,
                'checks' => array_map(
                    fn (string $k): array => ['key' => $k, 'severity' => $checkSeverity($k)],
                    $keys,
                ),
            ];
        };

        $coverage_matrix = [
            $covRow('Book-wide: stored balance vs ledger; trial balance; paired control totals', ['ledger_balances', 'global_trial', 'paired_control_totals']),
            $covRow('Master cash vs declared bank / statement balance (optional)', ['bank_statement_vs_book']),
            $covRow('Bank import rows → ledger posting hygiene', ['bank_transaction_posting_integrity']),
            $covRow('Bank pipeline: unposted imports / uncleared lines', ['bank_pipeline']),
            $covRow('SMS import rows → ledger posting hygiene', ['sms_transaction_posting_integrity']),
            $covRow('Member portal “post funds” → ledger', ['member_portal_posting_integrity']),
            $covRow('Contribution cycle: rows vs member fund + master fund legs', ['contribution_flow_integrity']),
            $covRow('Contributions: master fund credits & per-row ledger presence', ['contributions_ledger']),
            $covRow('Membership application subscription fee → cash + master fees', ['membership_application_fee_integrity']),
            $covRow('Annual subscription fee → master fees', ['subscription_fee_integrity']),
            $covRow('Loan disbursement: cash payout to member vs approved loan', ['loan_disbursement_cash_payout_integrity', 'approved_loans_disbursement_vs_ledger']),
            $covRow('Active loans: schedule vs loan ledger', ['active_loans_schedule_vs_ledger']),
            $covRow('Loan installments / repayments — paired flow', ['loan_installment_flow_integrity']),
            $covRow('Member cash transfers — debit/credit pairing', ['member_cash_transfer_integrity']),
            $covRow('Loan-type accounts missing a loan row', ['orphan_loan_accounts']),
        ];

        return [
            'meta' => $meta,
            'verdict' => $verdict,
            'checks' => $checks,
            'coverage_matrix' => $coverage_matrix,
            'pipeline' => $pipeline,
            'control_layer' => $controlLayer,
            'period_metrics' => $periodMetrics,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{
     *     ledger_account_outstanding: float,
     *     ledger_expected: float,
     *     scheduled_outstanding: float,
     *     partial_paid_ahead: float,
     *     delta: float,
     * }
     */
    private function loanOutstandingReconciliationMetrics(Loan $loan, float $ledgerAccountOutstanding): array
    {
        $breakdown = $loan->getOutstandingBreakdown();

        return [
            'ledger_account_outstanding' => round($ledgerAccountOutstanding, 2),
            'ledger_expected' => round($breakdown['ledger'], 2),
            'scheduled_outstanding' => round($breakdown['scheduled'], 2),
            'partial_paid_ahead' => round($breakdown['partial_paid'], 2),
            'delta' => round($ledgerAccountOutstanding - $breakdown['ledger'], 2),
        ];
    }

    /**
     * @return list<int>
     */
    private function legacyImportedLoanIds(): array
    {
        return LoanRepayment::query()
            ->where(function ($query): void {
                $query->where('notes', 'like', '%legacy-import:%')
                    ->orWhere('notes', 'like', '%Legacy migration%')
                    ->orWhere('notes', 'like', '%ترحيل البيانات التاريخية%');
            })
            ->distinct()
            ->pluck('loan_id')
            ->map(fn (mixed $loanId): int => (int) $loanId)
            ->filter(fn (int $loanId): bool => $loanId > 0)
            ->values()
            ->all();
    }

    /**
     * Legacy migration posts repayments on {@see LoanRepayment} and marks installments paid via schedule sync.
     *
     * @param  list<int>  $loanIds
     * @return list<array<string, mixed>>
     */
    private function legacyImportedLoanRepaymentFlowIssues(array $loanIds, ?int $masterFundId): array
    {
        if ($loanIds === []) {
            return [];
        }

        $issues = [];
        $repaymentMorph = LoanRepayment::class;

        foreach (array_chunk($loanIds, 50) as $chunk) {
            $loans = Loan::query()
                ->with('member')
                ->whereIn('id', $chunk)
                ->get();

            foreach ($loans as $loan) {
                $paidInstallmentSum = (float) $loan->installments()
                    ->where(function ($query): void {
                        $query->where('status', 'paid')->orWhere('paid_by_guarantor', true);
                    })
                    ->sum('amount');
                $repaymentSum = (float) $loan->repayments()->sum('amount');

                if (abs($paidInstallmentSum - $repaymentSum) > self::AMOUNT_TOLERANCE) {
                    $partialAhead = $loan->getPartialRepaymentAheadOfSchedule();
                    if (abs($repaymentSum - $paidInstallmentSum - $partialAhead) > self::AMOUNT_TOLERANCE) {
                        $issues[] = [
                            'loan_id' => $loan->id,
                            'issue' => 'legacy imported paid installments vs repayment records mismatch',
                            'expected' => round($paidInstallmentSum + $partialAhead, 2),
                            'actual' => round($repaymentSum, 2),
                            'scheduled_outstanding' => round($loan->getScheduledOutstanding(), 2),
                            'partial_paid_ahead' => round($partialAhead, 2),
                        ];
                    }

                    continue;
                }

                $repaymentIds = $loan->repayments()->pluck('id');

                if ($repaymentIds->isEmpty()) {
                    continue;
                }

                $borrowerId = (int) ($loan->member_id ?? 0);
                $memberFundCredits = (float) Transaction::query()
                    ->where('reference_type', $repaymentMorph)
                    ->whereIn('reference_id', $repaymentIds)
                    ->where('type', 'credit')
                    ->where('member_id', $borrowerId)
                    ->whereHas('account', fn ($query) => $query
                        ->where('type', 'fund')
                        ->where('member_id', $borrowerId))
                    ->sum('amount');

                if (abs($memberFundCredits - $repaymentSum) > self::AMOUNT_TOLERANCE) {
                    $issues[] = [
                        'loan_id' => $loan->id,
                        'issue' => 'legacy imported borrower member fund credit missing/mismatch',
                        'expected' => round($repaymentSum, 2),
                        'actual' => round($memberFundCredits, 2),
                        'member_id' => $borrowerId,
                    ];
                }

                $loanAccountCredits = (float) Transaction::query()
                    ->where('reference_type', $repaymentMorph)
                    ->whereIn('reference_id', $repaymentIds)
                    ->where('type', 'credit')
                    ->whereHas('account', fn ($query) => $query
                        ->where('type', 'loan')
                        ->where('loan_id', $loan->id))
                    ->sum('amount');

                if (abs($loanAccountCredits - $repaymentSum) > self::AMOUNT_TOLERANCE) {
                    $issues[] = [
                        'loan_id' => $loan->id,
                        'issue' => 'legacy imported loan account credit missing/mismatch',
                        'expected' => round($repaymentSum, 2),
                        'actual' => round($loanAccountCredits, 2),
                    ];
                }

                if ($masterFundId !== null) {
                    $masterFundCredits = (float) Transaction::query()
                        ->where('reference_type', $repaymentMorph)
                        ->whereIn('reference_id', $repaymentIds)
                        ->where('type', 'credit')
                        ->where('account_id', $masterFundId)
                        ->sum('amount');

                    if (abs($masterFundCredits - $repaymentSum) > self::AMOUNT_TOLERANCE) {
                        $issues[] = [
                            'loan_id' => $loan->id,
                            'issue' => 'legacy imported master fund credit missing/mismatch',
                            'expected' => round($repaymentSum, 2),
                            'actual' => round($masterFundCredits, 2),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * When the global trial fails, surface posting groups and account-type nets that explain the drift.
     *
     * @return array<string, mixed>
     */
    private function buildGlobalTrialDiagnostics(): array
    {
        $tolerance = self::AMOUNT_TOLERANCE;
        $groupCreditSumSql = "COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0)";
        $groupDebitSumSql = "COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0)";
        $groupPostingDeltaSql = 'ABS('.$groupCreditSumSql.' - '.$groupDebitSumSql.')';
        $creditSumSql = "COALESCE(SUM(CASE WHEN t.type = 'credit' THEN t.amount ELSE 0 END), 0)";
        $debitSumSql = "COALESCE(SUM(CASE WHEN t.type = 'debit' THEN t.amount ELSE 0 END), 0)";

        $unbalancedGroupsQuery = DB::table('transactions')
            ->selectRaw("
                reference_type,
                reference_id,
                {$groupCreditSumSql} as sum_credits,
                {$groupDebitSumSql} as sum_debits,
                COUNT(*) as line_count,
                MIN(description) as sample_description,
                MIN(transacted_at) as first_transacted_at
            ")
            ->whereNotNull('reference_id')
            ->whereNotNull('reference_type')
            ->groupBy('reference_type', 'reference_id')
            ->havingRaw("{$groupPostingDeltaSql} > ?", [$tolerance])
            ->orderByRaw("{$groupPostingDeltaSql} DESC");

        $unbalancedGroupCount = (int) DB::query()
            ->fromSub($unbalancedGroupsQuery, 'unbalanced_postings')
            ->count();

        $suspectedPostings = [];
        foreach ($unbalancedGroupsQuery->limit(75)->get() as $row) {
            $sumCredits = round((float) $row->sum_credits, 2);
            $sumDebits = round((float) $row->sum_debits, 2);

            $suspectedPostings[] = [
                'reference_type' => (string) $row->reference_type,
                'reference_id' => (int) $row->reference_id,
                'sum_credits' => $sumCredits,
                'sum_debits' => $sumDebits,
                'posting_delta' => round($sumCredits - $sumDebits, 2),
                'line_count' => (int) $row->line_count,
                'sample_description' => $row->sample_description !== null ? (string) $row->sample_description : null,
                'first_transacted_at' => $row->first_transacted_at !== null ? (string) $row->first_transacted_at : null,
            ];
        }

        $nullReference = Transaction::query()
            ->where(function ($query): void {
                $query->whereNull('reference_id')->orWhereNull('reference_type');
            })
            ->selectRaw("
                COUNT(*) as line_count,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as sum_credits,
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as sum_debits
            ")
            ->first();

        $nullReferenceCredits = round((float) ($nullReference->sum_credits ?? 0), 2);
        $nullReferenceDebits = round((float) ($nullReference->sum_debits ?? 0), 2);
        $nullReferenceLineCount = (int) ($nullReference->line_count ?? 0);

        $nullReferenceLines = [];
        if ($nullReferenceLineCount > 0) {
            foreach (
                Transaction::query()
                    ->where(function ($query): void {
                        $query->whereNull('reference_id')->orWhereNull('reference_type');
                    })
                    ->with(['account', 'member'])
                    ->orderByDesc('transacted_at')
                    ->orderByDesc('id')
                    ->limit(75)
                    ->get() as $transaction
            ) {
                $nullReferenceLines[] = [
                    'transaction_id' => (int) $transaction->id,
                    'type' => (string) $transaction->type,
                    'amount' => round((float) $transaction->amount, 2),
                    'description' => $transaction->displayDescription(),
                    'account_id' => (int) $transaction->account_id,
                    'account_type' => (string) ($transaction->account?->type ?? ''),
                    'account_scope' => $transaction->account?->is_master ? 'master' : 'member',
                    'member' => $transaction->member?->name,
                    'transacted_at' => $transaction->transacted_at?->toDateTimeString(),
                ];
            }
        }

        $netByAccountType = [];
        foreach (
            DB::table('transactions as t')
                ->join('accounts as a', 'a.id', '=', 't.account_id')
                ->selectRaw("
                    a.type as account_type,
                    a.is_master,
                    {$creditSumSql} as sum_credits,
                    {$debitSumSql} as sum_debits
                ")
                ->groupBy('a.type', 'a.is_master')
                ->orderBy('a.type')
                ->orderByDesc('a.is_master')
                ->get() as $row
        ) {
            $credits = round((float) $row->sum_credits, 2);
            $debits = round((float) $row->sum_debits, 2);
            $netDelta = round($credits - $debits, 2);

            if (abs($netDelta) <= $tolerance) {
                continue;
            }

            $netByAccountType[] = [
                'account_type' => (string) $row->account_type,
                'scope' => ((bool) $row->is_master) ? 'master' : 'member',
                'sum_credits' => $credits,
                'sum_debits' => $debits,
                'net_delta' => $netDelta,
            ];
        }

        usort(
            $netByAccountType,
            fn (array $a, array $b): int => abs($b['net_delta']) <=> abs($a['net_delta']),
        );

        return [
            'unbalanced_posting_group_count' => $unbalancedGroupCount,
            'suspected_postings' => $suspectedPostings,
            'suspected_postings_truncated' => $unbalancedGroupCount > count($suspectedPostings),
            'null_reference_line_count' => $nullReferenceLineCount,
            'null_reference_credits' => $nullReferenceCredits,
            'null_reference_debits' => $nullReferenceDebits,
            'null_reference_delta' => round($nullReferenceCredits - $nullReferenceDebits, 2),
            'null_reference_lines' => $nullReferenceLines,
            'null_reference_lines_truncated' => $nullReferenceLineCount > count($nullReferenceLines),
            'net_by_account_type' => array_slice($netByAccountType, 0, 20),
            'resolution_hints' => [
                __('Each posting group (same reference type and ID) should have equal total credits and debits. Groups listed below are the most likely source of trial drift.'),
                __('Lines without a reference often come from manual adjustments — open a row below, then use the Linked source column or filter on the account ledger to review or correct the entry.'),
                __('Account-type nets show where credits and debits fail to cancel; member cash and fund accounts commonly carry net drift when only one pool leg was posted.'),
                __('Cross-check related checks: stored balance vs ledger, paired control totals, and the flow-specific integrity checks for contributions, loans, and bank imports.'),
            ],
        ];
    }

    /**
     * Build options array from {@see Setting} keys for CLI / scheduler.
     *
     * Keys: reconciliation.bank_statement_balance (numeric), reconciliation.bank_statement_date (Y-m-d),
     *       reconciliation.bank_variance_critical (boolish).
     *
     * @return array<string, mixed>
     */
    public static function bankOptionsFromSettings(): array
    {
        $balance = Setting::get('reconciliation', 'bank_statement_balance');
        $date = Setting::get('reconciliation', 'bank_statement_date');
        $critical = Setting::get('reconciliation', 'bank_variance_critical', false);

        $out = [];
        if ($balance !== null && $balance !== '' && is_numeric($balance)) {
            $out['declared_bank_balance'] = (float) $balance;
        }
        if (filled($date)) {
            $out['declared_bank_date'] = (string) $date;
        }
        $out['bank_mismatch_treat_as_critical'] = filter_var($critical, FILTER_VALIDATE_BOOL);

        return $out;
    }

    public function persistSnapshot(array $report, ?int $userId = null): ReconciliationSnapshot
    {
        $meta = $report['meta'];
        $verdict = $report['verdict'];

        return ReconciliationSnapshot::create([
            'mode' => $meta['mode'],
            'as_of' => Carbon::parse($meta['as_of']),
            'period_start' => filled($meta['period_start'] ?? null) ? Carbon::parse($meta['period_start']) : null,
            'period_end' => filled($meta['period_end'] ?? null) ? Carbon::parse($meta['period_end']) : null,
            'is_passing' => (bool) ($verdict['pass'] ?? false),
            'critical_issues' => (int) ($verdict['critical_issues'] ?? 0),
            'warnings' => (int) ($verdict['warnings'] ?? 0),
            'summary' => $report['summary'],
            'report' => $report,
            'created_by_id' => $userId,
        ]);
    }
}
