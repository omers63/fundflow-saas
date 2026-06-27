<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\ContributionCycleService;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationDateParser;
use App\Support\LegacyMigrationGraceCycleSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class LegacyPaymentClassifierService
{
    public const CLASSIFIED_PAYMENTS_DISK_PATH = 'legacy-migration/last-classified-payments.csv';

    public function __construct(
        private readonly LegacyMemberLoanSchedule $loanSchedule,
        private readonly LegacyMemberPaymentChronology $chronology,
        private readonly ContributionCycleService $cycles,
    ) {}

    public static function classifiedPaymentsAbsolutePath(): ?string
    {
        if (! Storage::disk('local')->exists(self::CLASSIFIED_PAYMENTS_DISK_PATH)) {
            return null;
        }

        return Storage::disk('local')->path(self::CLASSIFIED_PAYMENTS_DISK_PATH);
    }

    /**
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int}
     */
    public function summarizeClassifiedFile(string $absolutePath): array
    {
        $rows = AssociativeCsv::read($absolutePath);

        $stats = [
            'contributions' => 0,
            'loan_repayments' => 0,
            'ignored' => 0,
            'failed' => 0,
        ];

        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['payment_type'] ?? '')));

            match (true) {
                $type === 'contribution' => $stats['contributions']++,
                in_array($type, ['loan_repayment', 'loan', 'repayment'], true) => $stats['loan_repayments']++,
                in_array($type, ['ignore', 'skipped', 'skip'], true) => $stats['ignored']++,
                default => $stats['failed']++,
            };
        }

        return $stats;
    }

    /**
     * @return array{
     *     rows: list<array<string, string>>,
     *     stats: array{contribution: int, loan_repayment: int, ignore: int, unclassified: int, failed: int},
     *     errors: list<string>
     * }
     */
    public function classifyFile(
        string $absolutePath,
        ?Carbon $cutoffDate = null,
        ?string $membersCsvPath = null,
        ?string $loansCsvPath = null,
        ?int $graceCycles = null,
    ): array {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            throw new InvalidArgumentException(__('The payments file is empty or has no data rows.'));
        }

        $memberIndex = filled($membersCsvPath) && is_readable($membersCsvPath)
            ? LegacyMigrationCsvMemberIndex::fromPath($membersCsvPath)
            : null;

        $loanIndex = filled($loansCsvPath) && is_readable($loansCsvPath)
            ? LegacyMigrationCsvLoanIndex::fromPath(
                $loansCsvPath,
                $graceCycles ?? LegacyMigrationGraceCycleSettings::graceCycles(),
            )
            : null;

        $stats = [
            'contribution' => 0,
            'loan_repayment' => 0,
            'ignore' => 0,
            'unclassified' => 0,
            'failed' => 0,
        ];

        $errors = [];
        /** @var list<array<string, string>> $classifiedRows */
        $classifiedRows = [];
        $groupedByMember = [];

        foreach ($rows as $index => $row) {
            $groupedByMember[$this->memberGroupKey($row)][] = [
                'index' => $index,
                'row' => $this->sanitizePaymentSourceRow($row),
                'line' => $index + 2,
            ];
        }

        foreach ($groupedByMember as $memberItems) {
            usort($memberItems, function (array $left, array $right): int {
                try {
                    $leftDate = LegacyMigrationDateParser::parse(
                        trim((string) ($left['row']['payment_date'] ?? '')),
                        $left['line'],
                    );
                    $rightDate = LegacyMigrationDateParser::parse(
                        trim((string) ($right['row']['payment_date'] ?? '')),
                        $right['line'],
                    );
                } catch (InvalidArgumentException) {
                    return $left['index'] <=> $right['index'];
                }

                $dateComparison = $leftDate->timestamp <=> $rightDate->timestamp;

                return $dateComparison !== 0 ? $dateComparison : ($left['index'] <=> $right['index']);
            });

            $member = null;
            $loanWindows = [];
            $cumulativeRepaidByLoanKey = [];
            /** @var list<array<string, string>> $memberClassifiedRows */
            $memberClassifiedRows = [];

            foreach ($memberItems as $item) {
                $row = $item['row'];
                $line = $item['line'];

                try {
                    if ($member === null) {
                        $member = $this->resolveMember($row, $line, $memberIndex);
                        $loanWindows = $this->loanSchedule->forMember($member, $loanIndex);
                    }

                    $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount', $line);
                    $paymentDate = $this->parsePaymentDate($this->cell($row, 'payment_date'), $line);

                    $allocation = $this->chronology->allocate(
                        $paymentDate,
                        $amount,
                        $loanWindows,
                        $cumulativeRepaidByLoanKey,
                    );

                    $memberClassifiedRows = array_merge(
                        $memberClassifiedRows,
                        $this->classifiedRowsForAllocation($member, $paymentDate, $allocation, $line, $row),
                    );
                } catch (InvalidArgumentException $exception) {
                    $stats['failed']++;
                    $errors[] = $exception->getMessage();
                }
            }

            foreach ($memberClassifiedRows as $classifiedRow) {
                $type = strtolower($classifiedRow['payment_type']);
                match ($type) {
                    'contribution' => $stats['contribution']++,
                    'loan_repayment' => $stats['loan_repayment']++,
                    default => null,
                };
            }

            $classifiedRows = array_merge($classifiedRows, $memberClassifiedRows);
        }

        return [
            'rows' => $classifiedRows,
            'stats' => $stats,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function classifiedRowsForAllocation(
        LegacyPaymentClassifyMember $member,
        Carbon $paymentDate,
        LegacyPaymentAllocation $allocation,
        int $line,
        array $row,
    ): array {
        $rows = [];

        if ($allocation->repaymentAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            $rows[] = $this->classifiedRow(
                $member,
                $paymentDate,
                $allocation->repaymentAmount,
                'loan_repayment',
                $allocation->loanId,
                $line,
                $row,
            );
        }

        if ($allocation->contributionAmount > LegacyLoanRepaymentTarget::AMOUNT_TOLERANCE) {
            $rows[] = $this->classifiedRow(
                $member,
                $paymentDate,
                $allocation->contributionAmount,
                'contribution',
                null,
                $line,
                $row,
                $this->resolveContributionPeriod($member, $paymentDate),
            );
        }

        if ($rows === []) {
            $rows[] = $this->classifiedRow(
                $member,
                $paymentDate,
                round($allocation->repaymentAmount + $allocation->contributionAmount, 2),
                'contribution',
                null,
                $line,
                $row,
                $this->resolveContributionPeriod($member, $paymentDate),
            );
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function writeClassifiedCsv(string $absolutePath, array $rows): void
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        AssociativeCsv::write($absolutePath, [
            'member_email',
            'member_number',
            'payment_date',
            'amount',
            'payment_type',
            'loan_number',
            'period',
            'migration_outcome',
            'notes',
        ], $rows);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function classifiedRow(
        LegacyPaymentClassifyMember $member,
        Carbon $paymentDate,
        float $amount,
        string $type,
        ?int $loanId,
        int $line,
        array $row,
        string $period = '',
    ): array {
        return [
            'member_email' => $member->email,
            'member_number' => $member->memberNumber,
            'payment_date' => $paymentDate->toDateString(),
            'amount' => (string) $amount,
            'payment_type' => $type,
            'loan_number' => $type === 'loan_repayment' && $loanId !== null ? (string) $loanId : '',
            'period' => $period,
            'notes' => $this->cell($row, 'notes') ?: __('Legacy migration classifier row :line', ['line' => $line]),
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function sanitizePaymentSourceRow(array $row): array
    {
        foreach (['payment_type', 'loan_number', 'suggested_loan_number', 'period', 'migration_outcome'] as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function resolveContributionPeriod(
        LegacyPaymentClassifyMember $member,
        Carbon $paymentDate,
    ): string {
        [$month, $year] = $this->cycles->cyclePeriodForDueDate($paymentDate);

        $joinedAt = $member->databaseMember?->joined_at;

        if ($joinedAt !== null) {
            $joinedMonth = Carbon::parse((string) $joinedAt)->startOfMonth();
            $periodMonth = Carbon::create($year, $month, 1)->startOfMonth();

            if ($periodMonth->lt($joinedMonth)) {
                $month = (int) $joinedMonth->month;
                $year = (int) $joinedMonth->year;
            }
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function memberGroupKey(array $row): string
    {
        $number = trim((string) ($row['member_number'] ?? ''));

        if ($number !== '') {
            return 'n:'.$number;
        }

        $email = strtolower(trim((string) ($row['member_email'] ?? '')));

        if ($email !== '') {
            return 'e:'.$email;
        }

        $nationalId = trim((string) ($row['national_id'] ?? ''));

        if ($nationalId !== '') {
            return 'id:'.$nationalId;
        }

        return 'name:'.mb_strtolower(trim((string) ($row['member_name'] ?? $row['name'] ?? '')));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveMember(array $row, int $line, ?LegacyMigrationCsvMemberIndex $memberIndex): LegacyPaymentClassifyMember
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $nationalId = $this->cell($row, 'national_id');
        $memberName = $this->cell($row, 'member_name') ?: $this->cell($row, 'name');

        if ($email === '' && $number === '' && $nationalId === '' && $memberName === '') {
            throw new InvalidArgumentException("Row {$line}: ".__('Provide member_email, member_number, national_id, or member_name.'));
        }

        $databaseMember = $this->tryResolveDatabaseMember($email, $number, $nationalId, $memberName);

        if ($databaseMember !== null) {
            return LegacyPaymentClassifyMember::fromDatabase($databaseMember);
        }

        $csvRow = $memberIndex?->find($number, $memberName);

        if ($csvRow !== null) {
            return LegacyPaymentClassifyMember::fromCsvRow($csvRow);
        }

        if ($number !== '') {
            throw new InvalidArgumentException("Row {$line}: ".__('No member found for member_number :number — import members first or upload the matching members CSV on this page.', [
                'number' => $number,
            ]));
        }

        if ($email !== '') {
            throw new InvalidArgumentException("Row {$line}: ".__('No member found for email :email — import members first or upload the matching members CSV on this page.', [
                'email' => $email,
            ]));
        }

        if ($nationalId !== '') {
            throw new InvalidArgumentException("Row {$line}: ".__('No member found for national_id :id — import members first or upload the matching members CSV on this page.', [
                'id' => $nationalId,
            ]));
        }

        throw new InvalidArgumentException("Row {$line}: ".__('member_name must match exactly one member in the database or members CSV.'));
    }

    private function tryResolveDatabaseMember(
        string $email,
        string $number,
        string $nationalId,
        string $memberName,
    ): ?Member {
        if ($number !== '') {
            return Member::query()->where('member_number', $number)->first();
        }

        if ($email !== '') {
            return Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($nationalId !== '') {
            $memberIds = MembershipApplication::query()
                ->where('national_id', $nationalId)
                ->whereNotNull('member_id')
                ->pluck('member_id')
                ->unique()
                ->values();

            if ($memberIds->count() !== 1) {
                return null;
            }

            return Member::query()->find($memberIds->first());
        }

        $matches = Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($memberName)])
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    private function parsePaymentDate(string $value, int $line): Carbon
    {
        return LegacyMigrationDateParser::parse($value, $line, 'payment_date');
    }

    private function parseMoney(string $value, string $column, int $line): float
    {
        if ($value === '' || ! is_numeric($value)) {
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
