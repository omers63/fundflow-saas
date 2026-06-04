<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Services\ContributionCycleService;
use App\Services\MasterAccountInvariantService;
use App\Services\MemberInvariantService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;

class FiscalCloseReadinessService
{
    public function __construct(
        protected FiscalClosePeriodResolver $periodResolver,
        protected MasterAccountInvariantService $masterInvariants,
        protected MemberInvariantService $memberInvariants,
        protected ContributionCycleService $contributionCycles,
    ) {}

    public function assess(?Carbon $proposedPeriodEnd = null, ?string $fiscalYearLabel = null): FiscalCloseReadinessReport
    {
        $period = $fiscalYearLabel !== null
            ? $this->periodResolver->resolvePeriodForLabel($fiscalYearLabel)
            : $this->periodResolver->resolvePeriodContaining();

        if ($proposedPeriodEnd !== null) {
            $proposedPeriodEnd = $proposedPeriodEnd->copy()->startOfDay();
            if ($proposedPeriodEnd->lt($period->periodStart) || $proposedPeriodEnd->gt($period->periodEnd)) {
                $period = new FiscalYearPeriod(
                    $period->label,
                    $period->periodStart,
                    $proposedPeriodEnd->copy()->endOfDay(),
                );
            }
        }

        $gates = [
            $this->checkMasterPoolMirrors(),
            $this->checkMemberDrifts(),
            $this->checkOpenFundPostings(),
            $this->checkOpenCashOuts(),
            $this->checkReconciliationExceptions(),
            $this->checkUnclearedBankLines(),
            $this->checkContributionCycleCompleteness($period->periodEnd),
            $this->checkLoanPortfolioConsistency(),
        ];

        return new FiscalCloseReadinessReport($period, BusinessDay::now(), $gates);
    }

    public function canProceed(FiscalCloseReadinessReport $report): bool
    {
        return $report->canProceed();
    }

    public function checkMasterPoolMirrors(): FiscalCloseGateResult
    {
        $result = $this->masterInvariants->check();
        $tolerance = ContributionPolicySettings::reconTolerance();

        if ($result['balanced']) {
            return new FiscalCloseGateResult(
                'MASTER_POOL_MIRRORS',
                __('Master pool mirrors'),
                FiscalCloseGateResult::STATUS_PASS,
                __('Master cash and fund pools match member totals within tolerance (:tolerance).', [
                    'tolerance' => number_format($tolerance, 2),
                ]),
                [
                    'fund_delta' => $result['fund_delta'],
                    'cash_delta' => $result['cash_delta'],
                ],
            );
        }

        return new FiscalCloseGateResult(
            'MASTER_POOL_MIRRORS',
            __('Master pool mirrors'),
            FiscalCloseGateResult::STATUS_FAIL,
            __('Master pool drift detected. Fund delta :fund, cash delta :cash (tolerance :tolerance).', [
                'fund' => number_format($result['fund_delta'], 2),
                'cash' => number_format($result['cash_delta'], 2),
                'tolerance' => number_format($tolerance, 2),
            ]),
            [
                'fund_delta' => $result['fund_delta'],
                'cash_delta' => $result['cash_delta'],
                'master_fund_pool' => $result['master_fund_pool'],
                'member_fund_sum' => $result['member_fund_sum'],
                'master_cash' => $result['master_cash'],
                'member_cash_sum' => $result['member_cash_sum'],
            ],
        );
    }

    public function checkMemberDrifts(): FiscalCloseGateResult
    {
        $tolerance = ContributionPolicySettings::reconTolerance();
        $drifting = [];

        Member::query()
            ->active()
            ->with(['cashAccount', 'fundAccount'])
            ->orderBy('id')
            ->chunkById(100, function ($members) use (&$drifting): void {
                foreach ($members as $member) {
                    $result = $this->memberInvariants->check($member);

                    if (! $result['balanced']) {
                        $drifting[] = [
                            'member_id' => $member->id,
                            'member_name' => $member->name,
                            'fund_drift' => $result['fund_drift'],
                            'cash_drift' => $result['cash_drift'],
                        ];
                    }
                }
            });

        if ($drifting === []) {
            return new FiscalCloseGateResult(
                'MEMBER_DRIFTS',
                __('Member ledger drift'),
                FiscalCloseGateResult::STATUS_PASS,
                __('All active members pass cash and fund drift checks.'),
            );
        }

        return new FiscalCloseGateResult(
            'MEMBER_DRIFTS',
            __('Member ledger drift'),
            FiscalCloseGateResult::STATUS_FAIL,
            __(':count active member(s) have cash or fund drift beyond tolerance.', ['count' => count($drifting)]),
            ['members' => array_slice($drifting, 0, 25)],
            count($drifting),
        );
    }

    public function checkOpenFundPostings(): FiscalCloseGateResult
    {
        $count = FundPosting::query()->pending()->count();

        if ($count === 0) {
            return new FiscalCloseGateResult(
                'OPEN_FUND_POSTINGS',
                __('Pending deposit requests'),
                FiscalCloseGateResult::STATUS_PASS,
                __('No pending deposit requests.'),
            );
        }

        return new FiscalCloseGateResult(
            'OPEN_FUND_POSTINGS',
            __('Pending deposit requests'),
            FiscalCloseGateResult::STATUS_FAIL,
            __(':count pending deposit request(s) must be accepted or rejected before close.', ['count' => $count]),
            ['count' => $count],
            $count,
        );
    }

    public function checkOpenCashOuts(): FiscalCloseGateResult
    {
        $count = CashOutRequest::query()->pending()->count();

        if ($count === 0) {
            return new FiscalCloseGateResult(
                'OPEN_CASH_OUTS',
                __('Pending cash-out requests'),
                FiscalCloseGateResult::STATUS_PASS,
                __('No pending cash-out requests.'),
            );
        }

        return new FiscalCloseGateResult(
            'OPEN_CASH_OUTS',
            __('Pending cash-out requests'),
            FiscalCloseGateResult::STATUS_FAIL,
            __(':count pending cash-out request(s) must be resolved before close.', ['count' => $count]),
            ['count' => $count],
            $count,
        );
    }

    public function checkReconciliationExceptions(): FiscalCloseGateResult
    {
        $open = ReconciliationException::query()->open()->get();
        $critical = $open->where('severity', 'critical');

        if ($open->isEmpty()) {
            return new FiscalCloseGateResult(
                'RECON_EXCEPTIONS',
                __('Reconciliation exceptions'),
                FiscalCloseGateResult::STATUS_PASS,
                __('No open reconciliation exceptions.'),
            );
        }

        if ($critical->isEmpty()) {
            return new FiscalCloseGateResult(
                'RECON_EXCEPTIONS',
                __('Reconciliation exceptions'),
                FiscalCloseGateResult::STATUS_WARN,
                __(':count open reconciliation exception(s); none are critical.', ['count' => $open->count()]),
                [
                    'codes' => $open->pluck('exception_code')->unique()->values()->all(),
                ],
                $open->count(),
            );
        }

        return new FiscalCloseGateResult(
            'RECON_EXCEPTIONS',
            __('Reconciliation exceptions'),
            FiscalCloseGateResult::STATUS_FAIL,
            __(':count critical reconciliation exception(s) are open.', ['count' => $critical->count()]),
            [
                'codes' => $critical->pluck('exception_code')->unique()->values()->all(),
            ],
            $critical->count(),
        );
    }

    public function checkUnclearedBankLines(): FiscalCloseGateResult
    {
        $count = BankTransaction::query()->uncleared()->count();

        if ($count === 0) {
            return new FiscalCloseGateResult(
                'UNCLEARED_BANK_LINES',
                __('Uncleared bank lines'),
                FiscalCloseGateResult::STATUS_PASS,
                __('All imported bank lines are cleared.'),
            );
        }

        return new FiscalCloseGateResult(
            'UNCLEARED_BANK_LINES',
            __('Uncleared bank lines'),
            FiscalCloseGateResult::STATUS_WARN,
            __(':count uncleared bank line(s). Document or match before purge; required for close if tied to pending payouts.', [
                'count' => $count,
            ]),
            ['count' => $count],
            $count,
        );
    }

    public function checkContributionCycleCompleteness(Carbon $periodEnd): FiscalCloseGateResult
    {
        [$openMonth, $openYear] = $this->contributionCycles->currentOpenPeriod();
        $missingCount = 0;
        $cursor = $periodEnd->copy()->startOfMonth();
        $limit = $cursor->copy()->subMonths(36);

        while ($cursor->gte($limit)) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if ($year > $openYear || ($year === $openYear && $month >= $openMonth)) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            $dueEnd = $this->contributionCycles->cycleDueEndAt($month, $year);
            if ($dueEnd->gt($periodEnd)) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            $missingCount += $this->contributionCycles
                ->pendingMembersQueryForPeriod($month, $year)
                ->count();

            $cursor->subMonthNoOverflow();
        }

        $openArrearsCount = Contribution::query()
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->whereDate('period', '<=', $periodEnd->toDateString())
            ->count();

        if ($missingCount > 0) {
            return new FiscalCloseGateResult(
                'CONTRIBUTION_CYCLES',
                __('Contribution cycle completeness'),
                FiscalCloseGateResult::STATUS_FAIL,
                __(':count liable member-period(s) before the open cycle have no contribution record.', [
                    'count' => $missingCount,
                ]),
                [
                    'missing_records' => $missingCount,
                    'open_arrears' => $openArrearsCount,
                ],
                $missingCount,
            );
        }

        return new FiscalCloseGateResult(
            'CONTRIBUTION_CYCLES',
            __('Contribution cycle completeness'),
            FiscalCloseGateResult::STATUS_PASS,
            $openArrearsCount > 0
            ? __('Contribution records exist for closed periods. :count open arrears row(s) will carry forward.', [
                'count' => $openArrearsCount,
            ])
            : __('Contribution records exist for all closed periods through :date.', [
                'date' => $periodEnd->toFormattedDateString(),
            ]),
            ['open_arrears' => $openArrearsCount],
            $openArrearsCount,
        );
    }

    public function checkLoanPortfolioConsistency(): FiscalCloseGateResult
    {
        $inFlight = Loan::query()->readyToDisburse()->count();
        $pendingReview = Loan::query()->needsDecision()->count();
        $activeCount = Loan::query()->active()->count();
        $inconsistent = Loan::query()
            ->whereIn('status', ['active', 'partially_disbursed', 'transferred'])
            ->get()
            ->filter(function (Loan $loan): bool {
                $outstanding = (float) $loan->installments()->whereIn('status', ['pending', 'overdue'])->sum('amount');
                $disbursed = (float) $loan->amount_disbursed;
                $repaid = (float) $loan->total_repaid;

                return $disbursed > 0 && $outstanding <= 0 && $repaid < $disbursed - 0.01 && $loan->status === 'active';
            })
            ->count();

        if ($inFlight > 0 || $pendingReview > 0) {
            return new FiscalCloseGateResult(
                'LOAN_PORTFOLIO',
                __('Loan portfolio consistency'),
                FiscalCloseGateResult::STATUS_FAIL,
                __(':disburse in-flight loan(s) and :review pending review must be resolved before close.', [
                    'disburse' => $inFlight,
                    'review' => $pendingReview,
                ]),
                [
                    'in_flight_disbursement' => $inFlight,
                    'pending_review' => $pendingReview,
                    'active_loans' => $activeCount,
                ],
                $inFlight + $pendingReview,
            );
        }

        if ($inconsistent > 0) {
            return new FiscalCloseGateResult(
                'LOAN_PORTFOLIO',
                __('Loan portfolio consistency'),
                FiscalCloseGateResult::STATUS_WARN,
                __(':count active loan(s) may need review (disbursed with no open installments).', [
                    'count' => $inconsistent,
                ]),
                ['active_loans' => $activeCount],
                $inconsistent,
            );
        }

        return new FiscalCloseGateResult(
            'LOAN_PORTFOLIO',
            __('Loan portfolio consistency'),
            FiscalCloseGateResult::STATUS_PASS,
            __(':count active loan(s); no in-flight disbursements.', ['count' => $activeCount]),
            ['active_loans' => $activeCount],
            $activeCount,
        );
    }
}
