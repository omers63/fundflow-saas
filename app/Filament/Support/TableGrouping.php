<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\Transaction;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class TableGrouping
{
    /**
     * @param  array<int, Group|string>  $groups
     */
    public static function apply(Table $table, array $groups): Table
    {
        return $table
            ->groups($groups)
            ->groupingSettingsInDropdownOnDesktop();
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
            Group::make('parent.name')
                ->label(__('Parent'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (Member $record): string => $record->parent?->name ?? __('Independent')),
            Group::make('joined_at')
                ->label(__('Joined'))
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
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
                ->date()
                ->collapsible(),
            Group::make('loan_id')
                ->label(__('Loan'))
                ->titlePrefixedWithLabel(false)
                ->getTitleFromRecordUsing(fn (LoanRepayment $record): string => __('Loan #:id', [
                    'id' => $record->loan_id,
                ])),
        ];
    }
}
