<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\Transaction;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class TableGrouping
{
    /**
     * Apply collapsible group-by options (required on every data table).
     *
     * Pair with: {@see Table::$columnManager} (enabled globally), {@see Table::filters()},
     * and {@see Table::toolbarActions()} containing at least one {@see BulkActionGroup}.
     *
     * @param  array<int, Group|string>  $groups
     */
    public static function apply(Table $table, array $groups): Table
    {
        return $table
            ->groups(self::collapsible($groups))
            ->groupingSettingsInDropdownOnDesktop();
    }

    /**
     * @param  array<int, Group>  $groups
     * @return array<int, Group>
     */
    public static function collapsible(array $groups): array
    {
        return array_map(
            fn (Group $group): Group => $group->collapsible(),
            $groups,
        );
    }

    /**
     * @return array<int, Group>
     */
    public static function accountTransactions(): array
    {
        return [
            Group::make('type')
                ->label(__('Type'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Transaction $record): string => $record->type === 'credit'
                    ? __('Credit')
                    : __('Debit')),
            Group::make('transacted_at')
                ->label(__('Date'))
                ->date(),
            Group::make('reference_type')
                ->label(__('Source'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(function (Transaction $record): string {
                    if (blank($record->reference_type)) {
                        return __('Manual / unlinked');
                    }

                    if ($record->reference_type === Transaction::class) {
                        return __('Reversal');
                    }

                    return class_basename($record->reference_type);
                }),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function bankTransactions(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (BankTransaction $record): string => match ($record->status) {
                    'imported' => __('Imported'),
                    'mirrored' => __('Mirrored'),
                    'posted' => __('Posted'),
                    'ignored' => __('Ignored'),
                    'duplicate' => __('Duplicate'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('transaction_date')
                ->label(__('Transaction date'))
                ->date(),
            Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (BankTransaction $record): string => $record->member?->name ?? __('Unassigned')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function members(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Member $record): string => Member::statusOptions()[$record->status] ?? ucfirst((string) $record->status)),
            Group::make('parent_member_id')
                ->label(__('Parent'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Member $record): string => $record->parent?->name ?? __('Independent')),
            Group::make('joined_at')
                ->label(__('Joined'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function memberAccounts(bool $includeType = false): array
    {
        $groups = [];

        if ($includeType) {
            $groups[] = Group::make('type')
                ->label(__('Account type'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn ($record): string => match ($record->type) {
                    'cash' => __('Cash'),
                    'fund' => __('Fund'),
                    default => ucfirst((string) $record->type),
                });
        }

        $groups[] = Group::make('member.name')
            ->label(__('Member'))
            ->titlePrefixedWithLabel(false);

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function loans(bool $includeMember = true): array
    {
        $groups = [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'approved' => __('Approved'),
                    'disbursed' => __('Disbursed'),
                    'repaying' => __('Repaying'),
                    'completed' => __('Completed'),
                    'defaulted' => __('Defaulted'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('applied_at')
                ->label(__('Applied'))
                ->date(),
        ];

        if ($includeMember) {
            $groups[] = Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false);
        }

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function contributions(bool $includeMember = true): array
    {
        $groups = [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Contribution $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'posted' => __('Posted'),
                    'failed' => __('Failed'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('period')
                ->label(__('Contribution period'))
                ->date(),
        ];

        if ($includeMember) {
            $groups[] = Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false);
        }

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function fundPostings(bool $includeMember = true): array
    {
        $groups = [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (FundPosting $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'accepted' => __('Accepted'),
                    'rejected' => __('Rejected'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('posting_date')
                ->label(__('Posting date'))
                ->date(),
        ];

        if ($includeMember) {
            $groups[] = Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false);
        }

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function bankStatements(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (BankStatement $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'processing' => __('Processing'),
                    'completed' => __('Completed'),
                    'failed' => __('Failed'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('statement_date')
                ->label(__('Statement date'))
                ->date(),
            Group::make('bank_name')
                ->label(__('Bank'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (BankStatement $record): string => filled($record->bank_name)
                    ? (string) $record->bank_name
                    : __('Unknown bank')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function membershipApplications(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (MembershipApplication $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'approved' => __('Approved'),
                    'rejected' => __('Rejected'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('application_type')
                ->label(__('Application type'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (MembershipApplication $record): string => ucfirst((string) $record->application_type)),
            Group::make('created_at')
                ->label(__('Submitted'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function loanRepayments(): array
    {
        return [
            Group::make('paid_at')
                ->label(__('Paid on'))
                ->date(),
            Group::make('loan_id')
                ->label(__('Loan'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (LoanRepayment $record): string => __('Loan #:id', [
                    'id' => $record->loan_id,
                ])),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function fundAuditLogs(): array
    {
        return [
            Group::make('domain')
                ->label(__('Domain'))
                ->titlePrefixedWithLabel(false),
            Group::make('event_type')
                ->label(__('Event'))
                ->titlePrefixedWithLabel(false),
            Group::make('occurred_at')
                ->label(__('Occurred'))
                ->date(),
            Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (FundAuditLog $record): string => $record->member?->name ?? __('—')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function reconciliationExceptions(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (ReconciliationException $record): string => match ($record->status) {
                    ReconciliationException::STATUS_OPEN => __('Open'),
                    ReconciliationException::STATUS_RESOLVED => __('Resolved'),
                    ReconciliationException::STATUS_ESCALATED => __('Escalated'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('severity')
                ->label(__('Severity'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (ReconciliationException $record): string => ucfirst((string) $record->severity)),
            Group::make('domain')
                ->label(__('Domain'))
                ->titlePrefixedWithLabel(false),
            Group::make('raised_at')
                ->label(__('Raised'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function loanEligibilityOverrideRequests(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false),
            Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false),
            Group::make('created_at')
                ->label(__('Submitted'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function loanEligibilityOverrides(): array
    {
        return [
            Group::make('gate')
                ->label(__('Gate'))
                ->titlePrefixedWithLabel(false),
            Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false),
            Group::make('created_at')
                ->label(__('Recorded'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function monthlyStatements(bool $includeMember = true): array
    {
        $groups = [
            Group::make('period')
                ->label(__('Period'))
                ->titlePrefixedWithLabel(false),
            Group::make('generated_at')
                ->label(__('Generated'))
                ->date(),
        ];

        if ($includeMember) {
            $groups[] = Group::make('member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false);
        }

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function loanInstallments(bool $includeLoanMember = false): array
    {
        $groups = [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (LoanInstallment $record): string => match ($record->status) {
                    'pending' => __('Pending'),
                    'paid' => __('Paid'),
                    'overdue' => __('Overdue'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('due_date')
                ->label(__('Due date'))
                ->date(),
        ];

        if ($includeLoanMember) {
            $groups[] = Group::make('loan.member.name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false);
        }

        return $groups;
    }

    /**
     * @return array<int, Group>
     */
    public static function loanDisbursements(): array
    {
        return [
            Group::make('disbursed_at')
                ->label(__('Disbursed'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function directMessages(): array
    {
        return [
            Group::make('read_at')
                ->label(__('Read state'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (DirectMessage $record): string => $record->read_at !== null
                    ? __('Read')
                    : __('Unread')),
            Group::make('created_at')
                ->label(__('Sent'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function configurationTiers(): array
    {
        return [
            Group::make('is_active')
                ->label(__('Active'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (LoanTier|FundTier $record): string => $record->is_active
                    ? __('Active')
                    : __('Inactive')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function systemJobRuns(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (SystemJobRun $record): string => match ($record->status) {
                    SystemJobRun::STATUS_SUCCESS => __('Success'),
                    SystemJobRun::STATUS_FAILED => __('Failed'),
                    SystemJobRun::STATUS_RUNNING => __('Running'),
                    default => ucfirst((string) $record->status),
                }),
            Group::make('job_key')
                ->label(__('Job'))
                ->titlePrefixedWithLabel(false),
            Group::make('trigger')
                ->label(__('Trigger'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (SystemJobRun $record): string => match ($record->trigger) {
                    SystemJobRun::TRIGGER_SCHEDULE => __('Scheduled'),
                    SystemJobRun::TRIGGER_MANUAL => __('Manual'),
                    default => ucfirst((string) $record->trigger),
                }),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function systemJobCatalog(): array
    {
        return [
            Group::make('category')
                ->label(__('Category'))
                ->titlePrefixedWithLabel(false),
            Group::make('last_status')
                ->label(__('Last status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (array $record): string => match ($record['last_status'] ?? null) {
                    SystemJobRun::STATUS_SUCCESS => __('Success'),
                    SystemJobRun::STATUS_FAILED => __('Failed'),
                    SystemJobRun::STATUS_RUNNING => __('Running'),
                    default => __('Never'),
                }),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function loanQueue(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => Loan::statusOptions()[$record->status] ?? ucfirst((string) $record->status)),
            Group::make('is_emergency')
                ->label(__('Emergency'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => $record->is_emergency
                    ? __('Emergency')
                    : __('Standard')),
            Group::make('fundTier.label')
                ->label(__('Fund tier'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => $record->fundTier?->label ?? __('—')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function delinquencyContributionArrears(): array
    {
        return [
            Group::make('contribution_status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false),
            Group::make('member_name')
                ->label(__('Member'))
                ->titlePrefixedWithLabel(false),
            Group::make('year')
                ->label(__('Year'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (array $record): string => (string) ($record['year'] ?? __('Unknown'))),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function delinquencyGuarantorLoans(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => Loan::statusOptions()[$record->status] ?? ucfirst((string) $record->status)),
            Group::make('member.name')
                ->label(__('Borrower'))
                ->titlePrefixedWithLabel(false),
            Group::make('guarantor.name')
                ->label(__('Guarantor'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Loan $record): string => $record->guarantor?->name ?? __('—')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function centralTenants(): array
    {
        return [
            Group::make('plan.name')
                ->label(__('Plan'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Tenant $record): string => $record->plan?->name ?? __('—')),
            Group::make('is_provisioned')
                ->label(__('Provisioned'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Tenant $record): string => $record->is_provisioned
                    ? __('Provisioned')
                    : __('Pending')),
            Group::make('created_at')
                ->label(__('Created'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function centralPlans(): array
    {
        return [
            Group::make('billing_cycle')
                ->label(__('Billing cycle'))
                ->titlePrefixedWithLabel(false),
            Group::make('is_active')
                ->label(__('Active'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Plan $record): string => $record->is_active
                    ? __('Active')
                    : __('Inactive')),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function centralInvoices(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Invoice $record): string => ucfirst((string) $record->status)),
            Group::make('tenant.name')
                ->label(__('Tenant'))
                ->titlePrefixedWithLabel(false),
            Group::make('created_at')
                ->label(__('Created'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function centralSubscriptions(): array
    {
        return [
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Subscription $record): string => ucfirst((string) $record->status)),
            Group::make('plan.name')
                ->label(__('Plan'))
                ->titlePrefixedWithLabel(false),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function databaseBackups(): array
    {
        return [
            Group::make('driver')
                ->label(__('Driver'))
                ->titlePrefixedWithLabel(false),
            Group::make('created_at')
                ->label(__('Created'))
                ->date(),
        ];
    }

    /**
     * @return array<int, Group>
     */
    public static function notificationLogs(): array
    {
        return [
            Group::make('channel')
                ->label(__('Channel'))
                ->titlePrefixedWithLabel(false),
            Group::make('status')
                ->label(__('Status'))
                ->titlePrefixedWithLabel(false),
            Group::make('sent_at')
                ->label(__('Sent'))
                ->date(),
        ];
    }
}
