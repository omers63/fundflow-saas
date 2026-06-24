<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
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
        private readonly LegacyMigrationDatabaseLoanResolver $loanResolver,
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
        private readonly LegacyPaymentLoanAllocator $loanAllocator,
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
                    $loanRepaymentAmount = 0.0;
                } else {
                    $repaymentWindow = $this->repaymentWindowResolver->resolveWindow(
                        $member,
                        $paymentDate,
                        $cumulativeRepaidByLoanKey,
                        $loanIndex,
                    );

                    $allocation = $this->resolveAllocation(
                        $member,
                        $amount,
                        $paymentDate,
                        $cumulativeRepaidByLoanKey,
                        $repaymentWindow,
                    );
                    $loanRepaymentAmount = $allocation['repayment_amount'];

                    if (in_array($explicitType, ['loan_repayment', 'loan', 'repayment'], true)) {
                        $type = $loanRepaymentAmount > 0.00001 ? 'loan_repayment' : 'contribution';
                    } else {
                        $type = $loanRepaymentAmount > 0.00001 ? 'loan_repayment' : 'contribution';
                    }

                    if ($type === 'loan_repayment' && $allocation['loan'] !== null) {
                        $repaymentWindow = $this->buildWindowFromLoan($member, $allocation['loan']);
                    }
                }

                if ($type === 'loan_repayment' && $repaymentWindow !== null) {
                    $repaymentWindow = $this->loanResolver->enrichWindow(
                        $repaymentWindow,
                        $member->memberNumber,
                    );
                }

                if ($type === 'loan_repayment' && $repaymentWindow !== null && $loanRepaymentAmount > 0.00001) {
                    $cumulativeRepaidByLoanKey[$repaymentWindow->loanKey] = round(
                        ($cumulativeRepaidByLoanKey[$repaymentWindow->loanKey] ?? 0.0) + $loanRepaymentAmount,
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
                    'loan_number' => $type === 'loan_repayment'
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
            'notes',
        ], $rows);
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return array{loan: ?Loan, repayment_amount: float, contribution_amount: float}
     */
    private function resolveAllocation(
        LegacyPaymentClassifyMember $member,
        float $amount,
        Carbon $paymentDate,
        array &$cumulativeRepaidByLoanKey,
        ?LegacyLoanRepaymentWindow $repaymentWindow = null,
    ): array {
        if ($member->databaseMember !== null) {
            return $this->loanAllocator->allocate(
                $member->databaseMember,
                $amount,
                $paymentDate,
                $cumulativeRepaidByLoanKey,
            );
        }

        if ($repaymentWindow === null || $amount <= 0.00001) {
            return [
                'loan' => null,
                'repayment_amount' => 0.0,
                'contribution_amount' => $amount,
            ];
        }

        $cumulative = $cumulativeRepaidByLoanKey[$repaymentWindow->loanKey] ?? 0.0;
        $minimumInstallment = (float) (LoanTier::forAmount($repaymentWindow->amountApproved)?->min_monthly_installment ?? 0);

        if (
            $minimumInstallment > 0.00001
            && $cumulative <= 0.00001
            && $amount + 0.00001 < $minimumInstallment
            && ! (
                $member->monthlyContribution > 0.00001
                && abs($amount - $member->monthlyContribution) <= 0.00001
            )
        ) {
            return [
                'loan' => null,
                'repayment_amount' => 0.0,
                'contribution_amount' => $amount,
            ];
        }

        if (! $repaymentWindow->hasRemainingRepayment($cumulative)) {
            return [
                'loan' => null,
                'repayment_amount' => 0.0,
                'contribution_amount' => $amount,
            ];
        }

        $repaymentAmount = round(min($amount, $repaymentWindow->remainingRepayment($cumulative)), 2);

        return [
            'loan' => null,
            'repayment_amount' => $repaymentAmount,
            'contribution_amount' => round(max(0.0, $amount - $repaymentAmount), 2),
        ];
    }

    private function buildWindowFromLoan(LegacyPaymentClassifyMember $member, Loan $loan): LegacyLoanRepaymentWindow
    {
        $approved = (float) ($loan->amount_approved ?? $loan->amount);
        $disbursedAt = $loan->disbursed_at?->copy()->startOfDay() ?? now()->startOfDay();

        return new LegacyLoanRepaymentWindow(
            loanKey: LegacyLoanRepaymentWindow::loanKey((string) $member->memberNumber, $disbursedAt),
            disbursedAt: $disbursedAt,
            amountApproved: $approved,
            repaymentTargetAmount: LegacyLoanRepaymentTarget::totalRepaymentDue($approved),
            firstRepaymentAt: LegacyLoanRepaymentWindow::firstRepaymentAtForLoan(
                $loan,
                LegacyMigrationGraceCycleSettings::graceCycles(),
            ),
            loanId: $loan->id,
        );
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
