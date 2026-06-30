<?php

declare(strict_types=1);

namespace App\Support\BankClearing;

use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;
use Illuminate\Database\Eloquent\Builder;

enum BankClearingQueueKind: string
{
    case BankImport = 'bank_import';

    case ReturnIn = 'return_in';

    case InvestOut = 'invest_out';

    case Fee = 'fee';

    case Expense = 'expense';

    case CashOut = 'cash_out';

    case Deposit = 'deposit';

    public function label(): string
    {
        return match ($this) {
            self::BankImport => __('Bank import'),
            self::ReturnIn => __('Return in'),
            self::InvestOut => __('Invest out'),
            self::Fee => __('Fee'),
            self::Expense => __('Expense'),
            self::CashOut => __('Cash out'),
            self::Deposit => __('Deposit'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public static function forRecord(BankTransaction $record, bool $isBankFileItem): self
    {
        if ($isBankFileItem) {
            return self::BankImport;
        }

        return match (true) {
            $record->invest_return_id !== null => self::ReturnIn,
            $record->invest_disbursement_id !== null => self::InvestOut,
            $record->fee_disbursement_id !== null => self::Fee,
            $record->expense_disbursement_id !== null => self::Expense,
            $record->cash_out_request_id !== null => self::CashOut,
            default => self::Deposit,
        };
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyScope(Builder $query, BankClearingMatchService $matching): Builder
    {
        return match ($this) {
            self::BankImport => $matching->applyBankLinesAwaitingPostingScope($query),
            self::ReturnIn => $query->whereNotNull('invest_return_id'),
            self::InvestOut => $query->whereNotNull('invest_disbursement_id'),
            self::Fee => $query->whereNotNull('fee_disbursement_id'),
            self::Expense => $query->whereNotNull('expense_disbursement_id'),
            self::CashOut => $query->whereNotNull('cash_out_request_id'),
            self::Deposit => $query->whereNotNull('fund_posting_id'),
        };
    }
}
