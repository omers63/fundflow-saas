<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ContributionImportService
{
    public function __construct(
        private readonly ContributionService $contributions,
    ) {}

    /**
     * Import contributions from a UTF-8 CSV with a header row.
     *
     * @return array{created: int, failed: int, errors: array<int, string>}
     */
    public function import(string $absolutePath): array
    {
        $this->authorizeImport();

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
                $this->importRow($row);
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
    private function authorizeImport(): void
    {
        $user = auth('tenant')->user();

        if ($user === null) {
            throw new AuthorizationException(__('You must be signed in to import contributions.'));
        }

        if ($user->is_admin) {
            return;
        }

        throw new AuthorizationException(__('You do not have permission to import contributions.'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(array $row): void
    {
        $member = $this->resolveMember($row);
        [$month, $year] = $this->parsePeriod($row);
        $amount = $this->parseAmount($row, $member);
        $status = $this->parseStatus($this->cell($row, 'status'));
        $lateFee = $this->parseOptionalMoney($this->cell($row, 'late_fee_amount'), 'late_fee_amount') ?? 0.0;
        $referenceNumber = $this->cell($row, 'reference_number') ?: null;
        $notes = $this->cell($row, 'notes') ?: null;
        $postedAt = $this->parseOptionalDateTime($this->cell($row, 'posted_at'));
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'paid_at')) ?? $postedAt;

        if ($member->isExemptFromContributions($month, $year)) {
            throw new \InvalidArgumentException(__('Member is exempt from contributions for this period.'));
        }

        if (Contribution::memberPeriodRecordExists((int) $member->id, $month, $year)) {
            throw new \InvalidArgumentException(__('A contribution already exists for this member and period.'));
        }

        DB::transaction(function () use ($member, $month, $year, $amount, $status, $lateFee, $referenceNumber, $notes, $postedAt, $paidAt): void {
            $contribution = Contribution::query()->create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $amount,
                'amount_due' => $amount,
                'amount_collected' => 0,
                'status' => $status === 'posted' ? 'pending' : $status,
                'collection_status' => ContributionCollectionStatus::PENDING,
                'payment_method' => Contribution::PAYMENT_METHOD_IMPORT_CSV,
                'late_fee_amount' => $lateFee > 0 ? $lateFee : null,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
            ]);

            if ($status === 'posted') {
                $this->contributions->postContribution($contribution->fresh());

                $contribution->refresh()->update([
                    'collection_status' => ContributionCollectionStatus::COLLECTED,
                    'amount_collected' => $amount,
                    'posted_at' => $postedAt ?? $contribution->posted_at,
                    'paid_at' => $paidAt ?? $contribution->paid_at,
                ]);
            }
        });
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveMember(array $row): Member
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $nationalId = $this->cell($row, 'national_id');
        $memberName = $this->cell($row, 'member_name');
        if ($memberName === '') {
            $memberName = $this->cell($row, 'name');
        }

        if ($email === '' && $number === '' && $nationalId === '' && $memberName === '') {
            throw new \InvalidArgumentException(__('Provide member_email, member_number, national_id, or member_name.'));
        }

        if ($email !== '') {
            $member = Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($member === null) {
                throw new \InvalidArgumentException("No member found for email: {$email}");
            }

            return $member;
        }

        if ($number !== '') {
            $member = Member::query()->where('member_number', $number)->first();
            if ($member === null) {
                throw new \InvalidArgumentException("No member found for member_number: {$number}");
            }

            return $member;
        }

        if ($nationalId !== '') {
            $memberIds = MembershipApplication::query()
                ->where('national_id', $nationalId)
                ->whereNotNull('member_id')
                ->pluck('member_id')
                ->unique()
                ->values();

            if ($memberIds->isEmpty()) {
                throw new \InvalidArgumentException("No member found for national_id: {$nationalId}");
            }

            if ($memberIds->count() > 1) {
                throw new \InvalidArgumentException(
                    "Multiple members found for national_id: {$nationalId}. Use member_number or member_email."
                );
            }

            $member = Member::query()->find($memberIds->first());
            if ($member === null) {
                throw new \InvalidArgumentException("No member found for national_id: {$nationalId}");
            }

            return $member;
        }

        $nameMatches = Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($memberName)])
            ->get();

        if ($nameMatches->isEmpty()) {
            throw new \InvalidArgumentException("No member found for member_name: {$memberName}");
        }

        if ($nameMatches->count() > 1) {
            throw new \InvalidArgumentException(
                "Multiple members found for member_name: {$memberName}. Use member_number, member_email, or national_id."
            );
        }

        return $nameMatches->first();
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: int, 1: int}
     */
    private function parsePeriod(array $row): array
    {
        $raw = $this->cell($row, 'period');

        if ($raw === '') {
            throw new \InvalidArgumentException(__('period is required (YYYY-MM or YYYY-MM-DD).'));
        }

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            $raw .= '-01';
        }

        try {
            $date = Carbon::parse($raw)->startOfMonth();
        } catch (Throwable) {
            throw new \InvalidArgumentException(__('Invalid period format. Use YYYY-MM or YYYY-MM-DD.'));
        }

        return [(int) $date->month, (int) $date->year];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseAmount(array $row, Member $member): float
    {
        $raw = $this->cell($row, 'amount');

        if ($raw === '') {
            $raw = $this->cell($row, 'amount_due');
        }

        if ($raw === '') {
            $fallback = (float) $member->monthly_contribution_amount;

            if ($fallback <= 0) {
                throw new \InvalidArgumentException(__('amount is required when the member has no monthly contribution amount.'));
            }

            return round($fallback, 2);
        }

        return $this->parseMoney($raw, 'amount');
    }

    private function parseStatus(string $value): string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return 'pending';
        }

        if (in_array($normalized, ['pending', 'posted', 'waived', 'failed'], true)) {
            return $normalized;
        }

        throw new \InvalidArgumentException(
            __('status must be pending, posted, waived, or failed (got: :value).', ['value' => $value])
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseAssociativeCsv(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new \InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new \InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv((string) $headerLine);
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);

        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv((string) $line);
            $assoc = [];
            foreach ($headers as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function parseMoney(string $value, string $column): float
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$column} is required.");
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("{$column} must be numeric (got: {$value})");
        }

        return round((float) $value, 2);
    }

    private function parseOptionalMoney(string $value, string $column): ?float
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("{$column} must be numeric (got: {$value})");
        }

        return round((float) $value, 2);
    }

    private function parseOptionalDateTime(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new \InvalidArgumentException(__('Invalid date/time: :value', ['value' => $value]));
        }
    }
}
