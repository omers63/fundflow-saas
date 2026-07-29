<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundStatusDigestNotification;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\AutomationScheduleSettings;
use App\Support\Insights\InsightFormatter;
use Illuminate\Support\Facades\Schema;

final class FundStatusDigestService
{
    public function __construct(
        protected LoanDelinquencyService $delinquency,
        protected CollectionArrearsCatalogService $arrearsCatalog,
    ) {}

    /**
     * @return array{
     *   currency: string,
     *   balances: array{cash: float, fund: float, bank: float, fees: float},
     *   counts: array{
     *     pending_deposits: int,
     *     pending_contributions: int,
     *     pending_member_requests: int,
     *     pending_cash_out_requests: int,
     *     pending_membership_applications: int,
     *     loan_queue: int,
     *     overdue_installments: int,
     *     contribution_arrears_periods: int,
     *     members_in_arrears: int,
     *     open_reconciliation_exceptions: int,
     *     open_cycle_arrears_total: int,
     *     open_cycle_period_label: string,
     *     catalog_consistency_issue_count: int
     *   }
     * }
     */
    public function buildDigest(): array
    {
        $masters = Account::master()->get()->keyBy('type');

        $cash = (float) ($masters->get('cash')?->balance ?? 0.0);
        $fund = (float) ($masters->get('fund')?->balance ?? 0.0);
        $bank = (float) ($masters->get('bank')?->balance ?? 0.0);
        $fees = (float) ($masters->get('fees')?->balance ?? 0.0);

        $pendingDeposits = FundPosting::query()
            ->pending()
            ->count();

        $pendingContributions = Contribution::query()
            ->pending()
            ->count();

        $pendingMemberRequests = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->count();

        $pendingCashOutRequests = CashOutRequest::query()
            ->pending()
            ->count();

        $pendingMembershipApplications = MembershipApplication::query()
            ->where('status', 'pending')
            ->count();

        $loanQueue = Loan::query()
            ->inQueue()
            ->count();

        $delinquencyCounts = $this->delinquency->digestCounts();

        $overdueInstallments = (int) ($delinquencyCounts['overdue_installments'] ?? 0);
        $contributionArrearsPeriods = (int) ($delinquencyCounts['contribution_arrears_periods'] ?? 0);
        $membersInArrears = (int) ($delinquencyCounts['members_in_arrears'] ?? 0);

        $openReconciliationExceptions = Schema::hasTable('reconciliation_exceptions')
            ? (int) ReconciliationException::query()
                ->open()
                ->count()
            : 0;

        $cycleSnapshot = $this->arrearsCatalog->openCycleSnapshot();
        $openCycleTotal = (int) ($cycleSnapshot['total_items'] ?? 0);
        $openCyclePeriodLabel = (string) ($cycleSnapshot['period_label'] ?? '');

        $catalogIssues = 0;
        try {
            $issuesPayload = $this->arrearsCatalog->catalogConsistencyIssues();
            $catalogIssues = (int) ($issuesPayload['issue_count'] ?? 0);
        } catch (\Throwable) {
            $catalogIssues = 0;
        }

        return [
            'currency' => InsightFormatter::currency(),
            'balances' => [
                'cash' => $cash,
                'fund' => $fund,
                'bank' => $bank,
                'fees' => $fees,
            ],
            'counts' => [
                'pending_deposits' => $pendingDeposits,
                'pending_contributions' => $pendingContributions,
                'pending_member_requests' => $pendingMemberRequests,
                'pending_cash_out_requests' => $pendingCashOutRequests,
                'pending_membership_applications' => $pendingMembershipApplications,
                'loan_queue' => $loanQueue,
                'overdue_installments' => $overdueInstallments,
                'contribution_arrears_periods' => $contributionArrearsPeriods,
                'members_in_arrears' => $membersInArrears,
                'open_reconciliation_exceptions' => $openReconciliationExceptions,
                'open_cycle_arrears_total' => $openCycleTotal,
                'open_cycle_period_label' => $openCyclePeriodLabel,
                'catalog_consistency_issue_count' => $catalogIssues,
            ],
        ];
    }

    public function notifyAdminsIfNeeded(): int
    {
        if (! AutomationScheduleSettings::notifyFundStatusDigest()) {
            return 0;
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->get();

        if ($admins->isEmpty()) {
            return 0;
        }

        $digest = $this->buildDigest();

        $reviewUrl = AuditSystemPage::getUrl();

        $notified = 0;
        foreach ($admins as $admin) {
            $admin->notify(new FundStatusDigestNotification(
                digest: $digest,
                actionUrl: $reviewUrl,
            ));

            $notified++;
        }

        return $notified;
    }
}
