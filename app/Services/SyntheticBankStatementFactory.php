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
            default => throw new InvalidArgumentException(__('Unsupported synthetic statement bucket: :filename', [
                'filename' => $filename,
            ])),
        };
    }
}
