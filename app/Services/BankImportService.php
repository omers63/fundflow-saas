<?php

namespace App\Services;

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\ImportDateFormats;
use Illuminate\Http\UploadedFile;

class BankImportService
{
    /**
     * Import a CSV bank statement file.
     *
     * @param  array<string, mixed>|null  $template
     * @return array{statement: BankStatement, imported: int, duplicates: int, errors: string[]}
     */
    public function importCsv(UploadedFile $file, ?int $importedBy = null, ?string $bankName = null, ?array $template = null, ?int $bankTemplateId = null): array
    {
        $template = $template ?? $this->getCsvTemplate();
        $errors = [];
        $imported = 0;
        $duplicates = 0;
        $totalRows = 0;

        $statement = BankStatement::create([
            'filename' => $file->getClientOriginalName(),
            'bank_name' => $bankName,
            'bank_template_id' => $bankTemplateId,
            'status' => 'processing',
            'imported_by' => $importedBy,
            'imported_at' => BusinessDay::now(),
        ]);

        try {
            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                throw new \RuntimeException('Could not read CSV file');
            }

            $encoding = $template['encoding'] ?? 'UTF-8';
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }

            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

            $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
            file_put_contents($tempFile, $content);
            $handle = fopen($tempFile, 'r');
            if ($handle === false) {
                throw new \RuntimeException('Could not open CSV file');
            }

            $headerRow = null;
            $skipRows = (int) ($template['skip_rows'] ?? 0);
            $rowIndex = 0;

            while (($row = fgetcsv($handle, 0, $template['delimiter'] ?? ',')) !== false) {
                $rowIndex++;

                if ($rowIndex <= $skipRows) {
                    continue;
                }

                if ($headerRow === null && ($template['has_header'] ?? true)) {
                    $headerRow = $row;

                    continue;
                }

                $totalRows++;

                try {
                    $parsed = $this->parseRow($row, $template, $headerRow);
                    if ($parsed === null) {
                        continue;
                    }

                    $hash = $this->generateHash($parsed, $template);

                    $rawData = $this->rawDataPayload($parsed, $row);

                    $existingId = $this->findDuplicateId($hash, $parsed, $template);

                    if ($existingId !== null) {
                        BankTransaction::withoutEvents(fn () => BankTransaction::create([
                            'bank_statement_id' => $statement->id,
                            'transaction_date' => $parsed['date'],
                            'description' => $parsed['description'],
                            'amount' => $parsed['amount'],
                            'reference' => $parsed['reference'] ?? null,
                            'transaction_type' => $parsed['type'] ?? null,
                            'status' => 'duplicate',
                            'hash' => $hash.'_dup_'.$totalRows,
                            'raw_data' => json_encode($rawData),
                            'is_cleared' => false,
                            'duplicate_of_id' => $existingId,
                        ]));

                        $duplicates++;

                        continue;
                    }

                    BankTransaction::withoutEvents(fn () => BankTransaction::create([
                        'bank_statement_id' => $statement->id,
                        'transaction_date' => $parsed['date'],
                        'description' => $parsed['description'],
                        'amount' => $parsed['amount'],
                        'reference' => $parsed['reference'] ?? null,
                        'transaction_type' => $parsed['type'] ?? null,
                        'status' => 'imported',
                        'hash' => $hash,
                        'raw_data' => json_encode($rawData),
                        // Remains uncleared until matched to an operation or posted via bank-file path.
                        'is_cleared' => false,
                        'cleared_at' => null,
                    ]));

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$totalRows}: {$e->getMessage()}";
                }
            }

            fclose($handle);
            @unlink($tempFile);

            $statementDate = BankTransaction::where('bank_statement_id', $statement->id)
                ->orderBy('transaction_date', 'desc')
                ->value('transaction_date');

            $statement->update([
                'statement_date' => $statementDate,
                'status' => 'completed',
            ]);

            $statement->refreshRowCounts();
        } catch (\Exception $e) {
            $statement->update(['status' => 'failed', 'notes' => $e->getMessage()]);
            throw $e;
        }

        return [
            'statement' => $statement->fresh(),
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, mixed>  $template
     * @param  array<int, string>|null  $headerRow
     * @return array{date: string, description: string, amount: float, reference: ?string, type: ?string, balance: ?float, extra: array<string, string>}|null
     */
    private function parseRow(array $row, array $template, ?array $headerRow): ?array
    {
        $columnMap = $template['columns'] ?? [];

        $dateCol = $this->resolveColumnIndex($columnMap['date'] ?? 0, $headerRow);

        $dateRaw = trim($row[$dateCol] ?? '');
        if (empty($dateRaw)) {
            return null;
        }

        $dateFormats = $template['date_formats'] ?? $template['date_format'] ?? 'Y-m-d';
        try {
            $date = ImportDateFormats::parse($dateRaw, $dateFormats);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $amountMode = $template['amount_mode'] ?? 'single';
        if ($amountMode === 'split') {
            $creditCol = isset($columnMap['credit']) ? $this->resolveColumnIndex($columnMap['credit'], $headerRow) : null;
            $debitCol = isset($columnMap['debit']) ? $this->resolveColumnIndex($columnMap['debit'], $headerRow) : null;
            $credit = $creditCol !== null ? $this->parseAmount($row[$creditCol] ?? '') : 0;
            $debit = $debitCol !== null ? $this->parseAmount($row[$debitCol] ?? '') : 0;
            $amount = $credit > 0 ? $credit : -abs($debit);
        } else {
            $amountCol = $this->resolveColumnIndex($columnMap['amount'] ?? 2, $headerRow);
            $amount = $this->parseAmount($row[$amountCol] ?? '');
        }

        if ($amount == 0) {
            return null;
        }

        $description = '';
        $reference = null;
        $type = null;
        $balance = null;
        $extra = [];

        $reservedKeys = ['date', 'amount', 'credit', 'debit'];
        foreach ($columnMap as $key => $col) {
            if (in_array($key, $reservedKeys)) {
                continue;
            }

            $colIdx = $this->resolveColumnIndex($col, $headerRow);
            $value = trim($row[$colIdx] ?? '');

            match ($key) {
                'description' => $description = $value,
                'reference' => $reference = $value,
                'type' => $type = $value,
                'balance' => $balance = $this->parseAmount($value),
                default => $extra[$key] = $value,
            };
        }

        return [
            'date' => $date->format('Y-m-d'),
            'description' => $description,
            'amount' => $amount,
            'reference' => $reference,
            'type' => $type,
            'balance' => $balance,
            'extra' => $extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<int, string>  $row
     * @return array<string, mixed>
     */
    private function rawDataPayload(array $parsed, array $row): array
    {
        $rawData = $parsed['extra'] ?? [];
        if (array_key_exists('balance', $parsed) && $parsed['balance'] !== null) {
            $rawData['balance'] = $parsed['balance'];
        }
        $rawData['_raw_csv'] = $row;

        return $rawData;
    }

    /**
     * Normalized value for a duplicate-detection field (core columns, reserved extras, or custom keys in `extra`).
     *
     * @param  array<string, mixed>  $parsed
     */
    private function duplicateFieldValue(array $parsed, string $field): string
    {
        return match ($field) {
            'date' => (string) ($parsed['date'] ?? ''),
            'amount' => $this->normalizeNumericFingerprint($parsed['amount'] ?? null),
            'description' => trim((string) ($parsed['description'] ?? '')),
            'reference' => trim((string) ($parsed['reference'] ?? '')),
            'type' => trim((string) ($parsed['type'] ?? '')),
            'balance' => $this->normalizeNumericFingerprint($parsed['balance'] ?? null),
            default => trim((string) (($parsed['extra'][$field] ?? '') ?: '')),
        };
    }

    private function normalizeNumericFingerprint(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) round((float) $value, 4);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $template
     */
    private function generateHash(array $parsed, array $template): string
    {
        $fields = $template['duplicate_fields'] ?? ['date', 'amount', 'description', 'reference'];
        $tolerance = (int) ($template['duplicate_date_tolerance'] ?? 0);

        $parts = [];
        foreach ($fields as $field) {
            if ($field === 'date' && $tolerance > 0) {
                $dayOfYear = (int) date('z', strtotime($parsed['date']));
                $window = $tolerance > 0 ? intdiv($dayOfYear, $tolerance + 1) : $dayOfYear;
                $parts[] = date('Y', strtotime($parsed['date'])).'-w'.$window;
            } else {
                $parts[] = $this->duplicateFieldValue($parsed, $field);
            }
        }

        return md5(implode('|', $parts));
    }

    /**
     * @param  array<string, mixed>  $parsedA
     * @param  array<string, mixed>  $parsedB
     * @param  array<string, mixed>  $template
     */
    private function duplicateFieldsMatch(array $parsedA, array $parsedB, array $template): bool
    {
        $fields = $template['duplicate_fields'] ?? ['date', 'amount', 'description', 'reference'];
        $tolerance = (int) ($template['duplicate_date_tolerance'] ?? 0);

        foreach ($fields as $field) {
            if ($field === 'date' && $tolerance > 0) {
                $a = strtotime($parsedA['date'] ?? '');
                $b = strtotime($parsedB['date'] ?? '');
                if ($a === false || $b === false) {
                    return false;
                }
                if (abs($a - $b) > $tolerance * 86400) {
                    return false;
                }

                continue;
            }

            if ($this->duplicateFieldValue($parsedA, $field) !== $this->duplicateFieldValue($parsedB, $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedFromStoredTransaction(BankTransaction $txn): array
    {
        $raw = json_decode($txn->raw_data, true) ?? [];
        unset($raw['_raw_csv']);

        $balance = null;
        if (array_key_exists('balance', $raw)) {
            $balance = is_numeric($raw['balance']) ? (float) $raw['balance'] : null;
            unset($raw['balance']);
        }

        return [
            'date' => $txn->transaction_date->format('Y-m-d'),
            'amount' => (float) $txn->amount,
            'description' => (string) ($txn->description ?? ''),
            'reference' => $txn->reference,
            'type' => $txn->transaction_type,
            'balance' => $balance,
            'extra' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $template
     */
    private function findDuplicateId(string $hash, array $parsed, array $template): ?int
    {
        $tolerance = (int) ($template['duplicate_date_tolerance'] ?? 0);

        $existing = BankTransaction::where('hash', $hash)
            ->where('status', '!=', 'duplicate')
            ->first();

        if ($existing) {
            return $existing->id;
        }

        if ($tolerance === 0) {
            return null;
        }

        $fields = $template['duplicate_fields'] ?? ['date', 'amount', 'description', 'reference'];
        $query = BankTransaction::where('status', '!=', 'duplicate');

        if (in_array('date', $fields, true)) {
            $date = $parsed['date'];
            $query->whereBetween('transaction_date', [
                date('Y-m-d', strtotime($date." -{$tolerance} days")),
                date('Y-m-d', strtotime($date." +{$tolerance} days")),
            ]);
        }

        if (in_array('amount', $fields, true)) {
            $query->where('amount', $parsed['amount']);
        }

        if (in_array('description', $fields, true)) {
            $query->where('description', $parsed['description'] ?? '');
        }

        if (in_array('reference', $fields, true)) {
            $ref = $parsed['reference'] ?? '';
            if ($ref !== '') {
                $query->where('reference', $ref);
            }
        }

        if (in_array('type', $fields, true)) {
            $query->where('transaction_type', $parsed['type'] ?? null);
        }

        foreach ($query->orderByDesc('id')->limit(500)->get() as $candidate) {
            if ($this->duplicateFieldsMatch($parsed, $this->parsedFromStoredTransaction($candidate), $template)) {
                return $candidate->id;
            }
        }

        return null;
    }

    private function resolveColumnIndex(int|string $column, ?array $headerRow): int
    {
        if (is_int($column)) {
            return $column;
        }

        if ($headerRow !== null) {
            $needle = trim($column);
            foreach ($headerRow as $index => $header) {
                if (trim($header) === $needle) {
                    return $index;
                }
            }
        }

        return 0;
    }

    private function parseAmount(string $value): float
    {
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);

        return (float) $cleaned;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCsvTemplate(): array
    {
        $templateJson = Setting::get('bank', 'csv_template');

        if ($templateJson) {
            $decoded = json_decode($templateJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return self::getDefaultTemplate();
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDefaultTemplate(): array
    {
        return [
            'encoding' => 'UTF-8',
            'delimiter' => ',',
            'has_header' => true,
            'skip_rows' => 0,
            'date_formats' => ['Y-m-d'],
            'date_format' => ['Y-m-d'],
            'amount_mode' => 'single',
            'columns' => [
                'date' => 0,
                'description' => 1,
                'amount' => 2,
                'reference' => 3,
            ],
            'duplicate_fields' => ['date', 'amount', 'description', 'reference'],
            'duplicate_date_tolerance' => 0,
        ];
    }
}
