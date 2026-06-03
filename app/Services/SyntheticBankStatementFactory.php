<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankStatement;
use App\Support\BankStatementBuckets;
use InvalidArgumentException;

final class SyntheticBankStatementFactory
{
    public function memberPostings(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MEMBER_POSTINGS);
    }

    public function memberCashOuts(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MEMBER_CASH_OUTS);
    }

    public function masterExpenseDisbursements(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MASTER_EXPENSE_DISBURSEMENTS);
    }

    public function masterFeeDisbursements(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MASTER_FEE_DISBURSEMENTS);
    }

    public function masterInvestDisbursements(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MASTER_INVEST_DISBURSEMENTS);
    }

    public function masterInvestReturns(): BankStatement
    {
        return $this->forFilename(BankStatementBuckets::MASTER_INVEST_RETURNS);
    }

    public function forFilename(string $filename): BankStatement
    {
        return BankStatement::firstOrCreate(
            ['filename' => $filename, 'status' => 'completed'],
            [
                'bank_name' => $this->bankNameForFilename($filename),
                'total_rows' => 0,
                'imported_rows' => 0,
                'duplicate_rows' => 0,
            ],
        );
    }

    private function bankNameForFilename(string $filename): string
    {
        return match ($filename) {
            BankStatementBuckets::MEMBER_POSTINGS => __('Member postings'),
            BankStatementBuckets::MEMBER_CASH_OUTS => __('Member cash outs'),
            BankStatementBuckets::MASTER_EXPENSE_DISBURSEMENTS => __('Master expense disbursements'),
            BankStatementBuckets::MASTER_FEE_DISBURSEMENTS => __('Master fee disbursements'),
            BankStatementBuckets::MASTER_INVEST_DISBURSEMENTS => __('Master invest disbursements'),
            BankStatementBuckets::MASTER_INVEST_RETURNS => __('Master invest returns'),
            default => throw new InvalidArgumentException(__('Unsupported synthetic statement bucket: :filename', [
                'filename' => $filename,
            ])),
        };
    }
}
