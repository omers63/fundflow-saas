<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\Transaction;
use Illuminate\Database\Eloquent\Builder;

final class TransactionBusinessTypeCatalog
{
    public const CONTRIBUTION = 'contribution';

    public const EMI = 'emi';

    public const DEPOSIT = 'deposit';

    public const CASH_OUT = 'cash_out';

    public const LOAN = 'loan';

    public const LATE_FEE = 'late_fee';

    public const TRANSFER = 'transfer';

    public const REVERSAL = 'reversal';

    public const MANUAL = 'manual';

    public const BANK_IMPORT = 'bank_import';

    public const MEMBERSHIP_APPLICATION = 'membership_application';

    public const INVESTMENT = 'investment';

    public const INVESTMENT_RETURN = 'investment_return';

    public const EXPENSE = 'expense';

    public const OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::CONTRIBUTION => self::labelForKey(self::CONTRIBUTION),
            self::EMI => self::labelForKey(self::EMI),
            self::DEPOSIT => self::labelForKey(self::DEPOSIT),
            self::CASH_OUT => self::labelForKey(self::CASH_OUT),
            self::LOAN => self::labelForKey(self::LOAN),
            self::LATE_FEE => self::labelForKey(self::LATE_FEE),
            self::TRANSFER => self::labelForKey(self::TRANSFER),
            self::REVERSAL => self::labelForKey(self::REVERSAL),
            self::MANUAL => self::labelForKey(self::MANUAL),
            self::BANK_IMPORT => self::labelForKey(self::BANK_IMPORT),
            self::MEMBERSHIP_APPLICATION => self::labelForKey(self::MEMBERSHIP_APPLICATION),
            self::INVESTMENT => self::labelForKey(self::INVESTMENT),
            self::INVESTMENT_RETURN => self::labelForKey(self::INVESTMENT_RETURN),
            self::EXPENSE => self::labelForKey(self::EXPENSE),
        ];
    }

    public static function labelFor(Transaction $transaction): string
    {
        return self::labelForKey(self::keyFor($transaction));
    }

    public static function labelForKey(string $key): string
    {
        return match ($key) {
            self::CONTRIBUTION => __('Contribution'),
            self::EMI => __('EMI'),
            self::DEPOSIT => __('Deposit'),
            self::CASH_OUT => __('Cash out'),
            self::LOAN => __('Loan'),
            self::LATE_FEE => __('Late fee'),
            self::TRANSFER => __('Allocation'),
            self::REVERSAL => __('Reversal'),
            self::MANUAL => __('Manual / unlinked'),
            self::BANK_IMPORT => __('Bank import'),
            self::MEMBERSHIP_APPLICATION => __('Membership application'),
            self::INVESTMENT => __('Investment'),
            self::INVESTMENT_RETURN => __('Investment return'),
            self::EXPENSE => __('Expense'),
            default => __('Other'),
        };
    }

    public static function keyFor(Transaction $transaction): string
    {
        if (self::isLateFeeDescription($transaction->description)) {
            return self::LATE_FEE;
        }

        if (self::hasTransferDescription($transaction->description)) {
            return self::TRANSFER;
        }

        if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
            return self::MANUAL;
        }

        return match ($transaction->reference_type) {
            Transaction::class => self::REVERSAL,
            Contribution::class => self::CONTRIBUTION,
            FundPosting::class => self::DEPOSIT,
            LoanInstallment::class, LoanRepayment::class => self::EMI,
            Loan::class => self::LOAN,
            CashOutRequest::class => self::CASH_OUT,
            FeeDeduction::class => self::LATE_FEE,
            BankTransaction::class => self::BANK_IMPORT,
            MembershipApplication::class => self::MEMBERSHIP_APPLICATION,
            InvestDisbursement::class => self::INVESTMENT,
            InvestReturn::class => self::INVESTMENT_RETURN,
            ExpenseDisbursement::class => self::EXPENSE,
            default => self::OTHER,
        };
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public static function applyFilter(Builder $query, ?string $key): Builder
    {
        return match ($key) {
            self::CONTRIBUTION => self::excludeLateFeeDescriptions(
                $query->where('reference_type', Contribution::class),
            ),
            self::EMI => self::excludeLateFeeDescriptions(
                $query->whereIn('reference_type', [LoanInstallment::class, LoanRepayment::class]),
            ),
            self::DEPOSIT => $query->where('reference_type', FundPosting::class),
            self::CASH_OUT => $query->where('reference_type', CashOutRequest::class),
            self::LOAN => $query->where('reference_type', Loan::class),
            self::LATE_FEE => $query->where(function (Builder $inner): void {
                $inner->where('reference_type', FeeDeduction::class)
                    ->orWhere('description', 'like', self::contributionLateFeePrefix().'%')
                    ->orWhere('description', 'like', self::emiLateFeePrefix().'%');
            }),
            self::TRANSFER => $query->where(function (Builder $inner): void {
                $inner->where('description', 'like', 'Transfer to%')
                    ->orWhere('description', 'like', 'Transfer from%')
                    ->orWhere('description', 'like', 'Allocation —%');
            }),
            self::REVERSAL => $query->where('reference_type', Transaction::class),
            self::MANUAL => $query->where(function (Builder $inner): void {
                $inner->whereNull('reference_id')
                    ->orWhereNull('reference_type');
            }),
            self::BANK_IMPORT => $query->where('reference_type', BankTransaction::class),
            self::MEMBERSHIP_APPLICATION => $query->where('reference_type', MembershipApplication::class),
            self::INVESTMENT => $query->where('reference_type', InvestDisbursement::class),
            self::INVESTMENT_RETURN => $query->where('reference_type', InvestReturn::class),
            self::EXPENSE => $query->where('reference_type', ExpenseDisbursement::class),
            default => $query,
        };
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private static function excludeLateFeeDescriptions(Builder $query): Builder
    {
        return $query
            ->where('description', 'not like', self::contributionLateFeePrefix().'%')
            ->where('description', 'not like', self::emiLateFeePrefix().'%');
    }

    private static function hasTransferDescription(?string $description): bool
    {
        if (blank($description)) {
            return false;
        }

        return preg_match('/^(Transfer to|Transfer from|Allocation —)/', (string) $description) === 1;
    }

    private static function isLateFeeDescription(?string $description): bool
    {
        if (blank($description)) {
            return false;
        }

        return preg_match('/^(Contribution late fee —|EMI late fee —)/', (string) $description) === 1;
    }

    private static function contributionLateFeePrefix(): string
    {
        return __('Contribution late fee —');
    }

    private static function emiLateFeePrefix(): string
    {
        return __('EMI late fee —');
    }
}
