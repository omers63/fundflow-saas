<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

final class AccountTransactionImportService
{
    public function __construct(
        private readonly AccountingService $accounting,
    ) {}

    /**
     * Import manual ledger credits and debits for a master account.
     *
     * @return array{created: int, failed: int, errors: array<int, string>}
     */
    public function import(Account $account, string $absolutePath): array
    {
        $this->authorizeImport($account);

        $created = 0;
        $failed = 0;
        $errors = [];

        $rows = $this->parseAssociativeCsv($absolutePath);

        if ($rows === []) {
            return [
                'created' => 0,
                'failed' => 0,
                'errors' => [__('The file is empty or has no data rows after the header.')],
            ];
        }

        $lineBase = 2;

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                $this->importRow($account, $row);
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        return [
            'created' => $created,
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
        $type = strtolower($this->cell($row, 'type'));

        if (! in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException(__('Type must be credit or debit.'));
        }

        $amount = $this->parseAmount($this->cell($row, 'amount'));
        $description = trim($this->cell($row, 'description'));

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $transactedAt = $this->parseDateTime($this->cell($row, 'transacted_at'));
        $memberId = $this->resolveMemberId($this->cell($row, 'member_number'));

        AccountingService::withoutMemberCashCollection(function () use ($account, $type, $amount, $description, $transactedAt, $memberId): void {
            if ($type === 'credit') {
                $this->accounting->postManualCredit($account, $amount, $description, $transactedAt, $memberId);

                return;
            }

            $this->accounting->postManualDebit($account, $amount, $description, $transactedAt, $memberId);
        });
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
