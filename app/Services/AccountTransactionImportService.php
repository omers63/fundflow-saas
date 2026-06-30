<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Support\MasterInvestLedgerImport;
use App\Support\MasterReserveLedgerDirection;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

final class AccountTransactionImportService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly MasterInvestInService $investIn,
        private readonly MasterInvestOutService $investOut,
        private readonly MasterExpenseDisbursementService $expenseDisbursements,
        private readonly MasterFeeDeductionService $feeDeductions,
        private readonly MasterFeeDisbursementService $feeDisbursements,
    ) {}

    /**
     * Import manual ledger movements for a master account.
     *
     * Reserve ledgers (invest, expense, fees) accept credit / debit and run the same workflows as the ledger actions.
     * Other master ledgers accept credit / debit manual postings.
     *
     * @return array{created: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function import(Account $account, string $absolutePath): array
    {
        $this->authorizeImport($account);

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $rows = $this->parseAssociativeCsv($absolutePath);

        if ($rows === []) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [__('The file is empty or has no data rows after the header.')],
            ];
        }

        $lineBase = 2;

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                if (MasterInvestLedgerImport::shouldSkipImportRow($account, $row)) {
                    $skipped++;

                    continue;
                }

                $this->importRow($account, $row);
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeImport(Account $account): void
    {
        $user = auth('tenant')->user();

        if ($user === null) {
            throw new AuthorizationException(__('You must be signed in to import ledger entries.'));
        }

        if (! $user->is_admin) {
            throw new AuthorizationException(__('You do not have permission to import ledger entries.'));
        }

        if (! $account->is_master) {
            throw new AuthorizationException(__('Ledger import is only available for master accounts.'));
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(Account $account, array $row): void
    {
        $amount = $this->parseAmount($this->cell($row, 'amount'));
        $description = trim($this->cell($row, 'description'));

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        if (MasterInvestLedgerImport::isInvestAccount($account)) {
            $description = MasterInvestLedgerImport::sanitizeInvestImportDescription($description);

            if ($description === '') {
                throw new InvalidArgumentException(__('Description is required.'));
            }
        }

        $transactedAt = $this->parseDateTime($this->resolveDateCell($row));
        $memberNumber = $this->cell($row, 'member_number');
        $type = MasterReserveLedgerDirection::normalizeImportType($this->cell($row, 'type'));

        if (MasterReserveLedgerDirection::isReserveLedger($account)) {
            $workflow = MasterReserveLedgerDirection::workflowFromLedgerType($type);

            if ($workflow === null) {
                throw new InvalidArgumentException(__('Type must be credit or debit.'));
            }

            $this->importReserveRow(
                $account,
                $workflow,
                $amount,
                $description,
                $transactedAt,
                $memberNumber,
            );

            return;
        }

        $memberId = $this->resolveMemberId($memberNumber);

        AccountingService::withoutMemberCashCollection(function () use ($account, $type, $amount, $description, $transactedAt, $memberId): void {
            if ($type === 'credit') {
                $this->accounting->postManualCredit($account, $amount, $description, $transactedAt, $memberId);

                return;
            }

            $this->accounting->postManualDebit($account, $amount, $description, $transactedAt, $memberId);
        });
    }

    private function importReserveRow(
        Account $account,
        string $direction,
        float $amount,
        string $description,
        Carbon $transactedAt,
        string $memberNumber,
    ): void {
        match ([$account->type, $direction]) {
            ['invest', 'in'] => $this->investIn->investIn($account, $amount, $description, $transactedAt),
            ['invest', 'out'] => $this->investOut->investOut($account, $amount, $description, $transactedAt),
            ['expense', 'in'] => $this->accounting->fundReserveAccountFromMasterFund(
                $account,
                $amount,
                $description,
                $transactedAt,
            ),
            ['expense', 'out'] => $this->expenseDisbursements->disburse(
                $account,
                $amount,
                $description,
                $transactedAt,
            ),
            ['fees', 'in'] => $this->importFeesIn($memberNumber, $amount, $description, $transactedAt),
            ['fees', 'out'] => $this->feeDisbursements->disburse(
                $account,
                $amount,
                $description,
                $transactedAt,
            ),
            default => throw new InvalidArgumentException(__('Unsupported reserve ledger movement.')),
        };
    }

    private function importFeesIn(
        string $memberNumber,
        float $amount,
        string $description,
        Carbon $transactedAt,
    ): void {
        if ($memberNumber === '') {
            throw new InvalidArgumentException(__('member_number is required for fee credits (deduction).'));
        }

        $member = Member::query()
            ->where('member_number', $memberNumber)
            ->first();

        if ($member === null) {
            throw new InvalidArgumentException(__('Member :number was not found.', ['number' => $memberNumber]));
        }

        $this->feeDeductions->deduct($member, $amount, $description, $transactedAt);
    }

    private function resolveMemberId(string $memberNumber): ?int
    {
        if ($memberNumber === '') {
            return null;
        }

        $member = Member::query()
            ->where('member_number', $memberNumber)
            ->first();

        if ($member === null) {
            throw new InvalidArgumentException(__('Member :number was not found.', ['number' => $memberNumber]));
        }

        return (int) $member->id;
    }

    private function parseAmount(string $value): float
    {
        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException(__('Amount must be a positive number.'));
        }

        $amount = (float) $value;

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        return $amount;
    }

    private function parseDateTime(string $value): Carbon
    {
        if ($value === '') {
            throw new InvalidArgumentException(__('Transaction date is required.'));
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException(__('Transaction date is invalid.'));
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveDateCell(array $row): string
    {
        foreach (['transacted_at', 'transaction_date', 'date'] as $column) {
            $value = $this->cell($row, $column);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        $normalized = [];

        foreach ($row as $header => $value) {
            $normalized[strtolower(trim((string) $header))] = trim((string) $value);
        }

        return $normalized[strtolower($key)] ?? '';
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseAssociativeCsv(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new InvalidArgumentException(__('Could not read the CSV file.'));
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(
            fn (mixed $column): string => strtolower(trim((string) $column)),
            $header,
        );

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }

            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($data[$index] ?? ''));
            }

            if (collect($row)->filter(fn (string $value): bool => $value !== '')->isEmpty()) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
