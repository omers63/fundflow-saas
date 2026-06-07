<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Support\BusinessDay;
use App\Support\CsvStringParser;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SmsImportService
{
    /**
     * Import an SMS CSV file (same flow as bank statement import, with automatic member match and cash posting).
     *
     * @return array{session: SmsImportSession, imported: int, duplicates: int, errors: int, posted: int}
     */
    public function importCsv(
        UploadedFile $file,
        string $relativeStoragePath,
        ?int $importedBy = null,
        ?string $bankName = null,
        ?int $templateId = null,
        ?string $notes = null,
    ): array {
        $template = $templateId !== null
            ? SmsImportTemplate::query()->findOrFail($templateId)
            : SmsImportTemplate::getDefault($bankName);

        if ($template === null) {
            throw new \RuntimeException(__('No SMS import template configured.'));
        }

        $session = SmsImportSession::query()->create([
            'bank_name' => filled($bankName) ? $bankName : $template->bank_name,
            'template_id' => $template->id,
            'imported_by' => $importedBy,
            'filename' => $file->getClientOriginalName(),
            'file_path' => ltrim($relativeStoragePath, '/'),
            'notes' => $notes,
            'status' => 'pending',
        ]);

        $stats = $this->import($session);

        return [
            'session' => $session->fresh(),
            ...$stats,
        ];
    }

    /**
     * @return array{imported: int, duplicates: int, errors: int, posted: int}
     */
    public function import(SmsImportSession $session): array
    {
        $session->update(['status' => 'processing']);

        $template = $session->template;

        if ($template === null) {
            $session->update([
                'status' => 'failed',
                'error_log' => [__('SMS import template was not found.')],
                'completed_at' => BusinessDay::now(),
            ]);

            return ['imported' => 0, 'duplicates' => 0, 'errors' => 1, 'posted' => 0];
        }

        try {
            $rows = $this->parseCsv($session->file_path, $template);
            $errors = [];

            $totalRows = count($rows);
            $importedCount = 0;
            $duplicateCount = 0;
            $errorCount = 0;
            $postedCount = 0;

            foreach ($rows as $lineNumber => $row) {
                try {
                    $parsed = $this->parseRow($row, $template);

                    if ($parsed === null) {
                        continue;
                    }

                    $duplicate = $this->findDuplicate($parsed, $template, $session->bank_name);
                    $matchedMemberId = $this->matchMember($parsed['raw_sms'], $template);

                    $tx = SmsTransaction::query()->create([
                        'bank_name' => $session->bank_name,
                        'import_session_id' => $session->id,
                        'member_id' => $matchedMemberId,
                        'transaction_date' => $parsed['date'],
                        'amount' => $parsed['amount'],
                        'transaction_type' => $parsed['type'],
                        'reference' => $parsed['reference'] ?? null,
                        'raw_sms' => $parsed['raw_sms'],
                        'raw_data' => $row,
                        'is_duplicate' => $duplicate !== null,
                        'duplicate_of_id' => $duplicate?->id,
                    ]);

                    if ($duplicate !== null) {
                        $duplicateCount++;
                    } else {
                        $importedCount++;

                        if ($this->tryAutoPost($tx, $session, $errors, $lineNumber)) {
                            $postedCount++;
                        }
                    }
                } catch (Throwable $e) {
                    $errorCount++;
                    $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
                }
            }

            $session->update([
                'status' => match (true) {
                    $errorCount > 0 && $importedCount === 0 => 'failed',
                    $errorCount > 0 => 'partially_completed',
                    default => 'completed',
                },
                'total_rows' => $totalRows,
                'imported_count' => $importedCount,
                'duplicate_count' => $duplicateCount,
                'error_count' => $errorCount,
                'error_log' => $errors !== [] ? $errors : null,
                'completed_at' => BusinessDay::now(),
            ]);

            return [
                'imported' => $importedCount,
                'duplicates' => $duplicateCount,
                'errors' => $errorCount,
                'posted' => $postedCount,
            ];
        } catch (Throwable $e) {
            $session->update([
                'status' => 'failed',
                'error_log' => [$e->getMessage()],
                'completed_at' => BusinessDay::now(),
            ]);

            return ['imported' => 0, 'duplicates' => 0, 'errors' => 1, 'posted' => 0];
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function tryAutoPost(SmsTransaction $tx, SmsImportSession $session, array &$errors, int|string $lineNumber): bool
    {
        if ($tx->is_duplicate || $tx->member_id === null) {
            return false;
        }

        $member = Member::query()->find($tx->member_id);

        if ($member === null) {
            return false;
        }

        try {
            app(AccountingService::class)->postSmsTransactionToCash($tx->fresh(), $member);

            return true;
        } catch (Throwable $e) {
            $errors[] = "Row {$lineNumber}: {$e->getMessage()}";

            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $filePath, SmsImportTemplate $template): array
    {
        $fullPath = $this->resolveCsvAbsolutePath($filePath);
        $content = file_get_contents($fullPath);

        if ($content === false) {
            throw new \RuntimeException(__('Cannot read the uploaded SMS file.'));
        }

        if ($template->encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $template->encoding);
        }

        $delimiter = $template->delimiter === '\t' ? "\t" : $template->delimiter;
        $rows = CsvStringParser::parseRows($content, $delimiter);

        if ($template->skip_rows > 0) {
            $rows = array_slice($rows, $template->skip_rows);
        }

        if ($rows === []) {
            return [];
        }

        if ($template->has_header) {
            $headers = array_map('trim', array_shift($rows));

            return array_map(function (array $row) use ($headers): array {
                $assoc = [];
                foreach ($headers as $i => $header) {
                    $assoc[$header] = $row[$i] ?? null;
                }

                return $assoc;
            }, $rows);
        }

        return $rows;
    }

    private function resolveCsvAbsolutePath(string $filePath): string
    {
        if (Storage::disk('public')->exists($filePath)) {
            return Storage::disk('public')->path($filePath);
        }

        return Storage::disk('local')->path($filePath);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{raw_sms: string, amount: ?float, date: ?string, type: string, reference: ?string}|null
     */
    private function parseRow(array $row, SmsImportTemplate $template): ?array
    {
        $get = fn (string $col) => $this->getColumn($row, $col, $template->has_header);

        $rawSms = trim((string) $get($template->sms_column));

        if ($rawSms === '') {
            return null;
        }

        $amount = $this->extractAmount($rawSms, $template->amount_pattern);

        $date = null;
        if (filled($template->date_column)) {
            $raw = trim((string) $get($template->date_column));
            if ($raw !== '') {
                $date = $this->parseDate($raw, $template->date_format);
            }
        }

        if ($date === null && filled($template->date_pattern)) {
            $extracted = $this->regexCapture($rawSms, $template->date_pattern, 'date');
            if ($extracted !== null && $extracted !== '') {
                $date = $this->parseDate($extracted, $template->date_pattern_format ?? $template->date_format);
            }
        }

        $reference = null;
        if (filled($template->reference_pattern)) {
            $reference = $this->regexCapture($rawSms, $template->reference_pattern, 'reference');
        }

        return [
            'raw_sms' => $rawSms,
            'amount' => $amount,
            'date' => $date,
            'type' => $this->detectType($rawSms, $template),
            'reference' => $reference,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function getColumn(array $row, int|string $column, bool $hasHeader): mixed
    {
        if (is_numeric($column) && ! $hasHeader) {
            return $row[(int) $column] ?? null;
        }

        return $row[(string) $column] ?? null;
    }

    private function extractAmount(string $text, ?string $pattern): ?float
    {
        if (! filled($pattern)) {
            return null;
        }

        $value = $this->regexCapture($text, $pattern, 'amount');

        if ($value === null || $value === '') {
            return null;
        }

        $clean = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $value));

        return filled($clean) ? (float) $clean : null;
    }

    private function parseDate(string $raw, ?string $format): ?string
    {
        if (! filled($format)) {
            return Carbon::parse($raw)->format('Y-m-d');
        }

        try {
            return Carbon::createFromFormat($format, trim($raw))->format('Y-m-d');
        } catch (Throwable) {
            return Carbon::parse(trim($raw))->format('Y-m-d');
        }
    }

    private function regexCapture(string $subject, string $pattern, string $group): ?string
    {
        $delimited = $this->ensureDelimiters($pattern);

        if (@preg_match($delimited, $subject, $matches) && isset($matches[$group])) {
            return trim($matches[$group]);
        }

        return null;
    }

    private function ensureDelimiters(string $pattern): string
    {
        $first = substr(ltrim($pattern), 0, 1);

        if (in_array($first, ['/', '#', '~', '@', '!'], true)) {
            return $pattern;
        }

        return '/'.str_replace('/', '\/', $pattern).'/ui';
    }

    private function detectType(string $text, SmsImportTemplate $template): string
    {
        $textLower = mb_strtolower($text);

        foreach ((array) ($template->credit_keywords ?? []) as $keyword) {
            if (str_contains($textLower, mb_strtolower((string) $keyword))) {
                return 'credit';
            }
        }

        foreach ((array) ($template->debit_keywords ?? []) as $keyword) {
            if (str_contains($textLower, mb_strtolower((string) $keyword))) {
                return 'debit';
            }
        }

        return $template->default_transaction_type ?? 'credit';
    }

    private function matchMember(string $smsText, SmsImportTemplate $template): ?int
    {
        if (! filled($template->member_match_pattern)) {
            return null;
        }

        $value = $this->regexCapture($smsText, $template->member_match_pattern, 'member');

        if ($value === null || $value === '') {
            return null;
        }

        $field = $template->member_match_field ?? 'member_number';

        if ($field === 'member_number') {
            return Member::query()->where('member_number', trim($value))->value('id');
        }

        if (in_array($field, ['member_name', 'user_name'], true)) {
            return Member::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))])
                ->value('id');
        }

        return null;
    }

    /**
     * @param  array{raw_sms: string, amount: ?float, date: ?string, type: string, reference: ?string}  $parsed
     */
    private function findDuplicate(array $parsed, SmsImportTemplate $template, ?string $bankName): ?SmsTransaction
    {
        $fields = $template->duplicate_match_fields ?? ['date', 'amount', 'reference'];
        $tolerance = $template->duplicate_date_tolerance ?? 0;

        $query = SmsTransaction::query()->where('is_duplicate', false);

        if (filled($bankName)) {
            $query->where('bank_name', $bankName);
        }

        if (in_array('date', $fields, true) && filled($parsed['date'])) {
            $date = Carbon::parse($parsed['date']);
            $query->whereBetween('transaction_date', [
                $date->copy()->subDays($tolerance)->toDateString(),
                $date->copy()->addDays($tolerance)->toDateString(),
            ]);
        }

        if (in_array('amount', $fields, true) && filled($parsed['amount'])) {
            $query->where('amount', $parsed['amount']);
        }

        if (in_array('type', $fields, true)) {
            $query->where('transaction_type', $parsed['type']);
        }

        if (in_array('reference', $fields, true) && filled($parsed['reference'])) {
            $query->where('reference', $parsed['reference']);
        }

        if (in_array('raw_sms', $fields, true) && filled($parsed['raw_sms'])) {
            $query->where('raw_sms', $parsed['raw_sms']);
        }

        return $query->first();
    }
}
