<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Resources\SupportRequests\SupportRequestResource;
use App\Filament\Tenant\Resources\Transactions\TransactionResource;
use App\Support\Lang;

/**
 * Canonical list of tenant admin sidebar consolidation (see docs/admin-portal-redesign-plan.md §5.1).
 */
final class TenantSidebarRegistry
{
    /**
     * @return list<class-string>
     */
    public static function hiddenFromSidebar(): array
    {
        return [
            AccountResource::class,
            MasterAccountResource::class,
            MemberRequestResource::class,
            MembershipApplicationResource::class,
            MessagesInboxPage::class,
            MonthlyStatementResource::class,
            SupportRequestResource::class,
        ];
    }

    /**
     * Primary sidebar entries after consolidation (excluding Dashboard).
     *
     * @return list<class-string>
     */
    public static function consolidatedNavigation(): array
    {
        return [
            MemberResource::class,
            LoansCluster::class,
            ContributionResource::class,
            DisbursementsPage::class,
            FundPostingResource::class,
            CashOutRequestResource::class,
            BankAccountsResource::class,
            TransactionResource::class,
            ReconciliationOverviewPage::class,
            ReportsPage::class,
            AuditSystemPage::class,
            Settings::class,
        ];
    }

    /**
     * @return list<string>
     */
    public static function consolidatedNavigationLabels(): array
    {
        return [
            Lang::formatUiLabel(__('Members')),
            Lang::formatUiLabel(__('Loans')),
            Lang::formatUiLabel(__('Collections')),
            Lang::formatUiLabel(__('Disbursements')),
            Lang::formatUiLabel(__('Deposits')),
            Lang::formatUiLabel(__('Cash outs')),
            Lang::formatUiLabel(__('Bank clearing')),
            Lang::formatUiLabel(__('Transactions')),
            Lang::formatUiLabel(__('Reconciliation')),
            Lang::formatUiLabel(__('Reports')),
            Lang::formatUiLabel(__('Audit & System')),
            Lang::formatUiLabel(__('Settings')),
        ];
    }

    public static function includesDashboard(): bool
    {
        return true;
    }

    public static function dashboardClass(): string
    {
        return Dashboard::class;
    }
}
