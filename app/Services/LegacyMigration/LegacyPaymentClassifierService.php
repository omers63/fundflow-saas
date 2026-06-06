<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Support\AssociativeCsv;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

final class LegacyPaymentClassifierService
{
    /**
     * @return array{
     *     rows: list<array<string, string>>,
     *     stats: array{contribution: int, loan_repayment: int, ignore: int, unclassified: int}
     * }
     */
    public function classifyFile(string $absolutePath, ?Carbon $cutoffDate = null): array
    {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            throw new InvalidArgumentException(__('The payments file is empty or has no data rows.'));
        }

        $classified = [];
        $stats = [
            'contribution' => 0,
            'loan_repayment' => 0,
            'ignore' => 0,
            'unclassified' => 0,
        ];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $member = $this->resolveMember($row, $line);
            $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount', $line);
            $paymentDate = $this->parsePaymentDate($this->cell($row, 'payment_date'), $line);
            $explicitType = strtolower($this->cell($row, 'payment_type'));

            if (in_array($explicitType, ['ignore', 'skipped', 'skip'], true)) {
                $type = 'ignore';
            } elseif (in_array($explicitType, ['contribution', 'loan_repayment', 'loan', 'repayment'], true)) {
                $type = $explicitType === 'contribution' ? 'contribution' : 'loan_repayment';
            } else {
                $type = $this->suggestType($member, $amount, $paymentDate, $cutoffDate);
            }

            $stats[$type]++;

            $classified[] = [
                'member_email' => (string) $member->email,
                'member_number' => (string) $member->member_number,
                'payment_date' => $paymentDate->toDateString(),
                'amount' => (string) $amount,
                'payment_type' => $type,
                'suggested_loan_number' => $type === 'loan_repayment'
                    ? (string) ($this->resolveActiveLoan($member)?->id ?? '')
                    : '',
                'period' => $type === 'contribution' ? $paymentDate->copy()->startOfMonth()->format('Y-m') : '',
                'notes' => $this->cell($row, 'notes') ?: __('Legacy migration classifier row :line', ['line' => $line]),
            ];
        }

        return [
            'rows' => $classified,
            'stats' => $stats,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function writeClassifiedCsv(string $absolutePath, array $rows): void
    {
        AssociativeCsv::write($absolutePath, [
            'member_email',
            'member_number',
            'payment_date',
            'amount',
            'payment_type',
            'suggested_loan_number',
            'period',
            'notes',
        ], $rows);
    }

    private function suggestType(Member $member, float $amount, Carbon $paymentDate, ?Carbon $cutoffDate): string
    {
        if ($cutoffDate !== null && $paymentDate->gt($cutoffDate)) {
            return 'unclassified';
        }

        $loan = $this->resolveActiveLoan($member);

        if ($loan !== null) {
            $outstanding = max(0.0, (float) $loan->amount_approved - (float) $loan->total_amount_repaid);

            if ($outstanding > 0.00001 && $amount <= ($outstanding + 0.02)) {
                return 'loan_repayment';
            }
        }

        $monthly = (float) $member->monthly_contribution_amount;

        if ($monthly > 0 && abs($amount - $monthly) <= 0.02) {
            return 'contribution';
        }

        if ($monthly > 0 && $amount > 0 && fmod($amount, $monthly) < 0.02) {
            return 'contribution';
        }

        return 'unclassified';
    }

    private function resolveActiveLoan(Member $member): ?Loan
    {
        return $member->loans()
            ->whereIn('status', ['active', 'transferred'])
            ->orderByDesc('disbursed_at')
            ->first();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveMember(array $row, int $line): Member
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $nationalId = $this->cell($row, 'national_id');
        $memberName = $this->cell($row, 'member_name') ?: $this->cell($row, 'name');

        if ($email === '' && $number === '' && $nationalId === '' && $memberName === '') {
            throw new InvalidArgumentException("Row {$line}: " . __('Provide member_email, member_number, national_id, or member_name.'));
        }

        if ($email !== '') {
            $member = Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($member === null) {
                throw new InvalidArgumentException("Row {$line}: No member found for email {$email}");
            }

            return $member;
        }

        if ($number !== '') {
            $member = Member::query()->where('member_number', $number)->first();
            if ($member === null) {
                throw new InvalidArgumentException("Row {$line}: No member found for member_number {$number}");
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

            if ($memberIds->count() !== 1) {
                throw new InvalidArgumentException("Row {$line}: " . __('national_id must match exactly one member.'));
            }

            $member = Member::query()->find($memberIds->first());
            if ($member === null) {
                throw new InvalidArgumentException("Row {$line}: No member found for national_id {$nationalId}");
            }

            return $member;
        }

        $matches = Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($memberName)])
            ->get();

        if ($matches->count() !== 1) {
            throw new InvalidArgumentException("Row {$line}: " . __('member_name must match exactly one member.'));
        }

        return $matches->first();
    }

    private function parsePaymentDate(string $value, int $line): Carbon
    {
        if ($value === '') {
            throw new InvalidArgumentException("Row {$line}: payment_date is required.");
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException("Row {$line}: Invalid payment_date {$value}");
        }
    }

    private function parseMoney(string $value, string $column, int $line): float
    {
        if ($value === '' || !is_numeric($value)) {
            throw new InvalidArgumentException("Row {$line}: {$column} must be numeric.");
        }

        return round((float) $value, 2);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function cell(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }
}
