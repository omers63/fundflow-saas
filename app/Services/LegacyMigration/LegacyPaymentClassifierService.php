<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationDateParser;
use Carbon\Carbon;
use InvalidArgumentException;

final class LegacyPaymentClassifierService
{
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
    ): array {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            throw new InvalidArgumentException(__('The payments file is empty or has no data rows.'));
        }

        $memberIndex = filled($membersCsvPath) && is_readable($membersCsvPath)
            ? LegacyMigrationCsvMemberIndex::fromPath($membersCsvPath)
            : null;

        $loanIndex = filled($loansCsvPath) && is_readable($loansCsvPath)
            ? LegacyMigrationCsvLoanIndex::fromPath($loansCsvPath)
            : null;

        $stats = [
            'contribution' => 0,
            'loan_repayment' => 0,
            'ignore' => 0,
            'unclassified' => 0,
            'failed' => 0,
        ];

        $errors = [];
        $cumulativeRepaidByLoanKey = [];
        $resultsByOriginalIndex = [];

        $orderedRows = [];

        foreach ($rows as $index => $row) {
            $orderedRows[] = [
                'index' => $index,
                'row' => $row,
                'line' => $index + 2,
            ];
        }

        usort($orderedRows, function (array $left, array $right): int {
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

        foreach ($orderedRows as $item) {
            $row = $item['row'];
            $line = $item['line'];
            $originalIndex = $item['index'];

            try {
                $member = $this->resolveMember($row, $line, $memberIndex);
                $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount', $line);
                $paymentDate = $this->parsePaymentDate($this->cell($row, 'payment_date'), $line);
                $explicitType = strtolower($this->cell($row, 'payment_type'));

                if (in_array($explicitType, ['ignore', 'skipped', 'skip'], true)) {
                    $type = 'ignore';
                    $repaymentWindow = null;
                } elseif (in_array($explicitType, ['contribution', 'loan_repayment', 'loan', 'repayment'], true)) {
                    $type = $explicitType === 'contribution' ? 'contribution' : 'loan_repayment';
                    $repaymentWindow = $type === 'loan_repayment'
                        ? $this->resolveRepaymentWindow($member, $paymentDate, $loanIndex, $cumulativeRepaidByLoanKey)
                        : null;
                } else {
                    $repaymentWindow = $this->resolveRepaymentWindow($member, $paymentDate, $loanIndex, $cumulativeRepaidByLoanKey);
                    $type = $this->suggestType(
                        $member,
                        $amount,
                        $paymentDate,
                        $cutoffDate,
                        $repaymentWindow,
                    );
                }

                if ($type === 'loan_repayment' && $repaymentWindow !== null) {
                    $cumulativeRepaidByLoanKey[$repaymentWindow->loanKey] = round(
                        ($cumulativeRepaidByLoanKey[$repaymentWindow->loanKey] ?? 0.0) + $amount,
                        2,
                    );
                }

                $stats[$type]++;

                $resultsByOriginalIndex[$originalIndex] = [
                    'member_email' => $member->email,
                    'member_number' => $member->memberNumber,
                    'payment_date' => $paymentDate->toDateString(),
                    'amount' => (string) $amount,
                    'payment_type' => $type,
                    'suggested_loan_number' => $type === 'loan_repayment'
                        ? (string) ($repaymentWindow?->loanId ?? '')
                        : '',
                    'period' => $type === 'contribution' ? $paymentDate->copy()->startOfMonth()->format('Y-m') : '',
                    'notes' => $this->cell($row, 'notes') ?: __('Legacy migration classifier row :line', ['line' => $line]),
                ];
            } catch (InvalidArgumentException $exception) {
                $stats['failed']++;
                $errors[] = $exception->getMessage();
            }
        }

        ksort($resultsByOriginalIndex);

        return [
            'rows' => array_values($resultsByOriginalIndex),
            'stats' => $stats,
            'errors' => $errors,
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

    private function suggestType(
        LegacyPaymentClassifyMember $member,
        float $amount,
        Carbon $paymentDate,
        ?Carbon $cutoffDate,
        ?LegacyLoanRepaymentWindow $repaymentWindow,
    ): string {
        if ($cutoffDate !== null && $paymentDate->gt($cutoffDate)) {
            return 'unclassified';
        }

        if ($repaymentWindow !== null && $amount > 0) {
            return 'loan_repayment';
        }

        $monthly = $member->monthlyContribution;

        if ($monthly > 0 && $this->amountMatches($amount, $monthly)) {
            return 'contribution';
        }

        if ($monthly > 0 && $amount > $monthly && $this->amountMatchesContributionArrears($amount, $monthly)) {
            return 'contribution';
        }

        return 'unclassified';
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function resolveRepaymentWindow(
        LegacyPaymentClassifyMember $member,
        Carbon $paymentDate,
        ?LegacyMigrationCsvLoanIndex $loanIndex,
        array $cumulativeRepaidByLoanKey,
    ): ?LegacyLoanRepaymentWindow {
        if ($member->databaseMember !== null) {
            return $this->resolveDatabaseRepaymentWindow($member->databaseMember, $paymentDate, $cumulativeRepaidByLoanKey);
        }

        return $loanIndex?->repaymentWindowAt($member->memberNumber, $paymentDate, $cumulativeRepaidByLoanKey);
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function resolveDatabaseRepaymentWindow(
        Member $member,
        Carbon $paymentDate,
        array $cumulativeRepaidByLoanKey,
    ): ?LegacyLoanRepaymentWindow {
        foreach ($member->loans()
            ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
            ->whereNotNull('disbursed_at')
            ->where('disbursed_at', '<=', $paymentDate)
            ->orderBy('disbursed_at')
            ->get() as $loan) {
            $window = $this->buildDatabaseRepaymentWindow($member, $loan);

            if (!$window->isDisbursedOnOrBefore($paymentDate)) {
                continue;
            }

            $cumulative = $cumulativeRepaidByLoanKey[$window->loanKey] ?? 0.0;

            if ($window->hasRemainingRepayment($cumulative)) {
                return $window;
            }
        }

        return null;
    }

    private function buildDatabaseRepaymentWindow(Member $member, Loan $loan): LegacyLoanRepaymentWindow
    {
        $approved = (float) ($loan->amount_approved ?? $loan->amount);
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey((string) $member->member_number, $disbursedAt),
            disbursedAt: $disbursedAt,
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::totalRepaymentDue($approved),
            loanId: $loan->id,
        );
    }

    private function amountMatches(float $amount, float $target): bool
    {
        return abs($amount - $target) <= 0.02;
    }

    private function amountMatchesContributionArrears(float $amount, float $monthly): bool
    {
        if ($monthly <= 0) {
            return false;
        }

        $months = round($amount / $monthly);

        if ($months < 2 || $months > 6) {
            return false;
        }

        return $this->amountMatches($amount, $months * $monthly);
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
            throw new InvalidArgumentException("Row {$line}: " . __('Provide member_email, member_number, national_id, or member_name.'));
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
            throw new InvalidArgumentException("Row {$line}: " . __('No member found for member_number :number — import members first or upload the matching members CSV on this page.', [
                'number' => $number,
            ]));
        }

        if ($email !== '') {
            throw new InvalidArgumentException("Row {$line}: " . __('No member found for email :email — import members first or upload the matching members CSV on this page.', [
                'email' => $email,
            ]));
        }

        if ($nationalId !== '') {
            throw new InvalidArgumentException("Row {$line}: " . __('No member found for national_id :id — import members first or upload the matching members CSV on this page.', [
                'id' => $nationalId,
            ]));
        }

        throw new InvalidArgumentException("Row {$line}: " . __('member_name must match exactly one member in the database or members CSV.'));
    }

    private function tryResolveDatabaseMember(
        string $email,
        string $number,
        string $nationalId,
        string $memberName,
    ): ?Member {
        if ($email !== '') {
            return Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($number !== '') {
            return Member::query()->where('member_number', $number)->first();
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
