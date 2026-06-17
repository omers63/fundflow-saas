<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\Loans\LoanLedgerService;
use App\Support\AssociativeCsv;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class LegacyPaymentImportService
{
    private const FUTURE_PERIOD_SEARCH_LIMIT = 600;

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly ContributionService $contributions,
        private readonly LoanLedgerService $ledger,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
        private readonly LegacyPaymentClassifierService $classifier,
    ) {}

    /**
     * Repair a classified payments CSV by downgrading unresolvable loan repayments to contributions.
     */
    public function repairClassifiedFile(string $absolutePath): int
    {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            return 0;
        }

        $reclassified = $this->precheckAndReclassifyUnresolvedRepayments($rows, []);

        if ($reclassified > 0) {
            $this->persistClassifiedRows($absolutePath, $rows);
        }

        return $reclassified;
    }

    /**
     * Import classified legacy payments (contribution / loan_repayment / ignore).
     *
     * @return array{contributions: int, future_contributions: int, loan_repayments: int, ignored: int, failed: int, reclassified_as_contribution: int, errors: list<string>}
     */
    public function import(string $absolutePath): array
    {
        return ContributionService::withoutPostedNotifications(
            fn (): array => ContributionService::withoutLiveCollectionGuards(
                fn (): array => $this->importRows($absolutePath),
            ),
        );
    }

    /**
     * @return array{contributions: int, future_contributions: int, loan_repayments: int, ignored: int, failed: int, reclassified_as_contribution: int, errors: list<string>}
     */
    private function importRows(string $absolutePath): array
    {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            return [
                'contributions' => 0,
                'future_contributions' => 0,
                'loan_repayments' => 0,
                'ignored' => 0,
                'failed' => 0,
                'reclassified_as_contribution' => 0,
                'errors' => [__('The file is empty or has no data rows after the header.')],
            ];
        }

        $contributions = 0;
        $futureContributions = 0;
        $loanRepayments = 0;
        $failed = 0;
        $reclassifiedAsContribution = 0;
        $errors = [];
        /** @var list<int> $affectedLoanIds */
        $affectedLoanIds = [];
        /** @var list<array{index: int, line: int, row: array<string, string>}> $deferredRepayments */
        $deferredRepayments = [];
        /** @var array<string, float> $cumulativeRepaidByLoanKey */
        $cumulativeRepaidByLoanKey = [];
        $lineBase = 2;

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                $type = strtolower($this->cell($row, 'payment_type'));

                match ($type) {
                    'ignore', 'skipped', 'skip' => $this->tallyContributionImport(
                        $this->importSkippedRowAsContribution($row),
                        $contributions,
                        $futureContributions,
                    ),
                    'contribution' => $this->tallyContributionImport(
                        $this->importContributionRow($row),
                        $contributions,
                        $futureContributions,
                    ),
                    'loan_repayment', 'loan', 'repayment' => $deferredRepayments[] = [
                        'index' => $index,
                        'line' => $lineNumber,
                        'row' => $row,
                    ],
                    'unclassified' => throw new InvalidArgumentException(__('Row is still unclassified — re-run payment classification or set payment_type to contribution or loan_repayment.')),
                    default => throw new InvalidArgumentException(__('Unknown payment_type: :type', ['type' => $type])),
                };
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        usort($deferredRepayments, function (array $left, array $right): int {
            $leftDate = $this->parseOptionalDateTime($this->cell($left['row'], 'payment_date')) ?? Carbon::minValue();
            $rightDate = $this->parseOptionalDateTime($this->cell($right['row'], 'payment_date')) ?? Carbon::minValue();

            $comparison = $leftDate->timestamp <=> $rightDate->timestamp;

            return $comparison !== 0 ? $comparison : ($left['line'] <=> $right['line']);
        });

        foreach ($deferredRepayments as $item) {
            try {
                $outcome = $this->importLoanRepaymentRow($item['row'], $affectedLoanIds, $cumulativeRepaidByLoanKey);

                if ($outcome === 'no_loan') {
                    $reclassifiedRow = $this->reclassifyRowAsContribution($item['row']);
                    $rows[$item['index']] = $reclassifiedRow;
                    $reclassifiedAsContribution++;

                    $this->tallyContributionImport(
                        $this->importContributionRow($reclassifiedRow),
                        $contributions,
                        $futureContributions,
                    );

                    continue;
                }

                if ($outcome === 'repayment') {
                    $loanRepayments++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$item['line']}: {$e->getMessage()}";
            }
        }

        if ($reclassifiedAsContribution > 0) {
            $this->persistClassifiedRows($absolutePath, $rows);
        }

        if ($affectedLoanIds !== []) {
            $this->scheduleSync->syncLoans($affectedLoanIds);
        }

        return [
            'contributions' => $contributions,
            'future_contributions' => $futureContributions,
            'loan_repayments' => $loanRepayments,
            'ignored' => 0,
            'failed' => $failed,
            'reclassified_as_contribution' => $reclassifiedAsContribution,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return 'contribution'|'future_contribution'
     */
    private function importSkippedRowAsContribution(array $row): string
    {
        $notes = $this->cell($row, 'notes');

        return $this->importContributionRow(array_merge($row, [
            'payment_type' => 'contribution',
            'notes' => $notes !== '' ? $notes : __('Skipped legacy payment'),
        ]));
    }

    /**
     * @param  array<string, string>  $row
     * @return 'contribution'|'future_contribution'
     */
    private function importContributionRow(array $row): string
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $periodRaw = $this->cell($row, 'period');
        $paymentDate = $this->cell($row, 'payment_date');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');

        $member = $this->resolveMemberForImportRow($email, $number);

        if ($member === null) {
            throw new InvalidArgumentException(__('Member not found for contribution row.'));
        }

        if ($this->legacyImportRowAlreadyPosted($member, $row)) {
            return $this->legacyImportRowWasFutureContribution($member, $row)
                ? 'future_contribution'
                : 'contribution';
        }

        [$month, $year] = $this->resolveContributionPeriod($periodRaw, $paymentDate);
        $postedAt = $paymentDate !== '' ? Carbon::parse($paymentDate) : BusinessDay::now();
        $baseNotes = $this->cell($row, 'notes') ?: __('Legacy migration contribution');

        if (! Contribution::memberPeriodRecordExists((int) $member->id, $month, $year)) {
            $this->postLegacyContribution(
                $member,
                $month,
                $year,
                $amount,
                $postedAt,
                $this->appendLegacyImportFingerprint($baseNotes, $row),
            );

            return 'contribution';
        }

        if ($this->legacyContributionRowAlreadyImported($member, $month, $year, $amount, $postedAt)) {
            return 'contribution';
        }

        $periodLabel = Carbon::create($year, $month, 1)->format('M Y');
        [$futureMonth, $futureYear] = $this->findNextAvailableContributionPeriod($member, $month, $year);
        $futureNotes = $this->appendLegacyImportFingerprint(
            '[legacy-routed] '.__('Routed from :period — :notes', [
                'period' => $periodLabel,
                'notes' => $baseNotes,
            ]),
            $row,
        );

        $this->postLegacyContribution(
            $member,
            $futureMonth,
            $futureYear,
            $amount,
            $postedAt,
            $futureNotes,
        );

        return 'future_contribution';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveContributionPeriod(string $periodRaw, string $paymentDate): array
    {
        if ($periodRaw === '') {
            if ($paymentDate === '') {
                throw new InvalidArgumentException(__('period or payment_date is required for contributions.'));
            }

            $periodRaw = Carbon::parse($paymentDate)->startOfMonth()->format('Y-m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $periodRaw) === 1) {
            $periodRaw .= '-01';
        }

        $date = Carbon::parse($periodRaw)->startOfMonth();

        return [(int) $date->month, (int) $date->year];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function findNextAvailableContributionPeriod(
        Member $member,
        int $afterMonth,
        int $afterYear,
    ): array {
        $cursor = Carbon::create($afterYear, $afterMonth, 1)->startOfMonth()->addMonthNoOverflow();

        for ($i = 0; $i < self::FUTURE_PERIOD_SEARCH_LIMIT; $i++) {
            $month = (int) $cursor->month;
            $year = (int) $cursor->year;

            if (! Contribution::memberPeriodRecordExists((int) $member->id, $month, $year)) {
                return [$month, $year];
            }

            $cursor->addMonthNoOverflow();
        }

        throw new InvalidArgumentException(__('No available future contribution period found for member :number.', [
            'number' => $member->member_number,
        ]));
    }

    private function postLegacyContribution(
        Member $member,
        int $month,
        int $year,
        float $amount,
        Carbon $postedAt,
        string $notes,
    ): void {
        DB::transaction(function () use ($member, $month, $year, $amount, $postedAt, $notes): void {
            $contribution = Contribution::query()->create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $amount,
                'amount_due' => $amount,
                'amount_collected' => 0,
                'status' => 'pending',
                'collection_status' => ContributionCollectionStatus::PENDING,
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'notes' => $notes,
            ]);

            $description = __('Contribution — :period', [
                'period' => Carbon::create($year, $month, 1)->format('M Y'),
            ]);

            AccountingService::withoutMemberCashCollection(function () use ($member, $amount, $description, $contribution, $postedAt): void {
                $this->accounting->creditMemberCashWithMasterMirror(
                    $member->cashAccount,
                    $amount,
                    $description,
                    __('(legacy contribution cash-in mirror)'),
                    $contribution,
                    $postedAt,
                    $member->id,
                );
            });

            $this->contributions->postContribution($contribution->fresh(), $postedAt);

            $contribution->refresh()->update([
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'amount_collected' => $amount,
                'posted_at' => $postedAt,
                'paid_at' => $postedAt,
            ]);
        });
    }

    /**
     * @param  'contribution'|'future_contribution'  $outcome
     */
    private function tallyContributionImport(
        string $outcome,
        int &$contributions,
        int &$futureContributions,
    ): void {
        if ($outcome === 'future_contribution') {
            $futureContributions++;
        } else {
            $contributions++;
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function precheckAndReclassifyUnresolvedRepayments(array &$rows, array $cumulativeRepaidByLoanKey): int
    {
        $reclassified = 0;
        /** @var list<array{index: int, row: array<string, string>}> $repaymentRows */
        $repaymentRows = [];

        foreach ($rows as $index => $row) {
            $type = strtolower($this->cell($row, 'payment_type'));

            if (! in_array($type, ['loan_repayment', 'loan', 'repayment'], true)) {
                continue;
            }

            $repaymentRows[] = [
                'index' => $index,
                'row' => $row,
            ];
        }

        usort($repaymentRows, function (array $left, array $right): int {
            $leftDate = $this->parseOptionalDateTime($this->cell($left['row'], 'payment_date')) ?? Carbon::minValue();
            $rightDate = $this->parseOptionalDateTime($this->cell($right['row'], 'payment_date')) ?? Carbon::minValue();

            $comparison = $leftDate->timestamp <=> $rightDate->timestamp;

            return $comparison !== 0 ? $comparison : ($left['index'] <=> $right['index']);
        });

        foreach ($repaymentRows as $item) {
            $row = $item['row'];
            $loan = $this->resolveLoanForImportRow($row, $cumulativeRepaidByLoanKey);

            if ($loan !== null) {
                $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
                $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);

                continue;
            }

            $email = strtolower($this->cell($row, 'member_email'));
            $number = $this->cell($row, 'member_number');

            if ($this->resolveMemberForImportRow($email, $number) === null) {
                continue;
            }

            $rows[$item['index']] = $this->reclassifyRowAsContribution($row);
            $reclassified++;
        }

        return $reclassified;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function resolveLoanForImportRow(array $row, array &$cumulativeRepaidByLoanKey): ?Loan
    {
        $loanNumber = $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number');
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'payment_date')) ?? BusinessDay::now();

        if ($loanNumber !== '' && is_numeric($loanNumber)) {
            $loan = Loan::query()->find((int) $loanNumber);

            if ($loan !== null) {
                return $loan;
            }
        }

        $member = $this->resolveMemberForImportRow($email, $number);

        if ($member === null) {
            return null;
        }

        return $this->repaymentWindowResolver->resolveLoan(
            $member,
            $paidAt,
            $amount,
            $cumulativeRepaidByLoanKey,
        );
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return 'repayment'|'no_loan'
     */
    private function importLoanRepaymentRow(array $row, array &$affectedLoanIds, array &$cumulativeRepaidByLoanKey): string
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'payment_date')) ?? BusinessDay::now();
        $notes = $this->cell($row, 'notes') ?: __('Legacy migration loan repayment');

        try {
            $loan = $this->resolveLoanForImportRow($row, $cumulativeRepaidByLoanKey);
        } catch (InvalidArgumentException) {
            return 'no_loan';
        }

        if ($loan === null) {
            if ($this->resolveMemberForImportRow($email, $number) === null) {
                throw new InvalidArgumentException(__('Member not found for loan repayment row.'));
            }

            return 'no_loan';
        }

        if (! in_array($loan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            throw new InvalidArgumentException(__('Loan must be active or settled to receive imported repayments.'));
        }

        if ($this->legacyLoanRepaymentAlreadyImported($loan, $amount, $paidAt)) {
            $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);

            return 'repayment';
        }

        DB::transaction(function () use ($loan, $amount, $paidAt, $notes): void {
            $repayment = LoanRepayment::query()->create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $this->ledger->postImportedLoanRepaymentWithCashFlow($loan->fresh(), $repayment, $amount, $paidAt);
        });

        $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);

        $affectedLoanIds[] = $loan->id;

        return 'repayment';
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function reclassifyRowAsContribution(array $row): array
    {
        $paymentDate = $this->cell($row, 'payment_date');
        $period = $this->cell($row, 'period');

        if ($period === '' && $paymentDate !== '') {
            $period = Carbon::parse($paymentDate)->startOfMonth()->format('Y-m');
        }

        $notes = $this->cell($row, 'notes');

        if ($notes === '') {
            $notes = __('Reclassified from loan repayment — no matching loan');
        }

        return array_merge($row, [
            'payment_type' => 'contribution',
            'loan_number' => '',
            'suggested_loan_number' => '',
            'period' => $period,
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function persistClassifiedRows(string $absolutePath, array $rows): void
    {
        $normalized = array_map(fn (array $row): array => [
            'member_email' => $this->cell($row, 'member_email'),
            'member_number' => $this->cell($row, 'member_number'),
            'payment_date' => $this->cell($row, 'payment_date'),
            'amount' => $this->cell($row, 'amount'),
            'payment_type' => $this->cell($row, 'payment_type'),
            'loan_number' => $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number'),
            'period' => $this->cell($row, 'period'),
            'notes' => $this->cell($row, 'notes'),
        ], array_values($rows));

        $this->classifier->writeClassifiedCsv($absolutePath, $normalized);

        $canonicalPath = LegacyPaymentClassifierService::classifiedPaymentsAbsolutePath();

        if ($canonicalPath !== null && $canonicalPath !== $absolutePath) {
            $this->classifier->writeClassifiedCsv($canonicalPath, $normalized);
        }
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
        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException("{$column} must be numeric.");
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
            throw new InvalidArgumentException(__('Invalid date/time: :value', ['value' => $value]));
        }
    }

    private function legacyLoanRepaymentAlreadyImported(Loan $loan, float $amount, Carbon $paidAt): bool
    {
        return LoanRepayment::query()
            ->where('loan_id', $loan->id)
            ->where('amount', $amount)
            ->whereDate('paid_at', $paidAt->toDateString())
            ->exists();
    }

    private function legacyContributionRowAlreadyImported(
        Member $member,
        int $month,
        int $year,
        float $amount,
        Carbon $postedAt,
    ): bool {
        $contribution = Contribution::findForMemberPeriod($member->id, $month, $year);

        if ($contribution === null) {
            return false;
        }

        if (abs((float) $contribution->amount - $amount) > 0.01) {
            return false;
        }

        return $contribution->paid_at?->toDateString() === $postedAt->toDateString();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function legacyImportRowAlreadyPosted(Member $member, array $row): bool
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('notes', 'like', '%'.$this->legacyImportFingerprint($row).'%')
            ->exists();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function legacyImportRowWasFutureContribution(Member $member, array $row): bool
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('notes', 'like', '%'.$this->legacyImportFingerprint($row).'%')
            ->where('notes', 'like', '%legacy-routed%')
            ->exists();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function appendLegacyImportFingerprint(string $notes, array $row): string
    {
        return $notes.' ['.$this->legacyImportFingerprint($row).']';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function legacyImportFingerprint(array $row): string
    {
        return 'legacy-import:'.implode('|', [
            $this->cell($row, 'member_number'),
            $this->cell($row, 'member_email'),
            $this->cell($row, 'payment_date'),
            $this->cell($row, 'amount'),
            $this->cell($row, 'payment_type'),
            $this->cell($row, 'period'),
        ]);
    }

    private function resolveMemberForImportRow(string $email, string $memberNumber): ?Member
    {
        if ($memberNumber !== '') {
            return Member::query()->where('member_number', $memberNumber)->first();
        }

        if ($email !== '') {
            return Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }

        return null;
    }
}
