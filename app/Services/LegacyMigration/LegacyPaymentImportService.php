<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Loans\LoanLedgerService;
use App\Support\AssociativeCsv;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\LegacyImportedLoan;
use App\Support\LegacyMigrationDateParser;
use App\Support\LegacyMigrationGraceCycleSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class LegacyPaymentImportService
{
    private const MAX_SUPPORTED_PAYMENT_DATE = '2037-12-31 23:59:59';

    private bool $simulationMode = false;

    /**
     * @var array<string, true>
     */
    private array $simulatedOccupiedPeriods = [];

    /**
     * @var list<array<string, string>>
     */
    private array $simulatedOutputRows = [];

    private ?LegacyMigrationCsvLoanIndex $loanIndexForImport = null;

    private ?LegacyLoanRepaymentInstallmentTracker $importInstallmentTracker = null;

    private bool $trustClassifiedBlueprint = false;

    /**
     * @var array<string, Member|null>
     */
    private array $memberImportCache = [];

    /**
     * @var array<string, bool>
     */
    private array $occupiedPeriodCache = [];

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly ContributionCycleService $cycles,
        private readonly ContributionService $contributions,
        private readonly LoanLedgerService $ledger,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
        private readonly LegacyPaymentClassifierService $classifier,
        private readonly LegacyMemberLoanSchedule $loanSchedule,
        private readonly LegacyMemberPaymentChronology $chronology,
        private readonly LegacyMigrationZeroBalanceLoanCompletionService $zeroBalanceLoanCompletion,
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
            $this->persistImportOutcomeRows($absolutePath, $rows);
        }

        return $reclassified;
    }

    /**
     * Import classified legacy payments (contribution / loan_repayment / ignore).
     *
     * @return array{contributions: int, future_contributions: int, loan_repayments: int, ignored: int, failed: int, reclassified_as_contribution: int, errors: list<string>}
     */
    public function import(string $absolutePath, ?string $loansCsvPath = null, ?int $graceCycles = null, bool $rebuildBalances = true): array
    {
        $this->loanIndexForImport = filled($loansCsvPath) && is_readable($loansCsvPath)
            ? LegacyMigrationCsvLoanIndex::fromPath(
                $loansCsvPath,
                $graceCycles ?? LegacyMigrationGraceCycleSettings::graceCycles(),
            )
            : null;

        try {
            return AccountingService::withoutMemberCashCollection(
                fn (): array => ContributionService::withoutPostedNotifications(
                    fn (): array => ContributionService::withoutLiveCollectionGuards(
                        fn(): array => $this->importRows($absolutePath, $rebuildBalances),
                    ),
                ),
            );
        } finally {
            $this->loanIndexForImport = null;
            $this->trustClassifiedBlueprint = false;
            $this->memberImportCache = [];
            $this->occupiedPeriodCache = [];
        }
    }

    /**
     * Simulate payment import after members and loans exist — matches {@see import()} without persisting.
     *
     * @return array{
     *     contributions: int,
     *     future_contributions: int,
     *     loan_repayments: int,
     *     ignored: int,
     *     failed: int,
     *     reclassified_as_contribution: int,
     *     errors: list<string>,
     *     rows: list<array<string, string>>
     * }
     */
    public function simulateMigrationPayments(string $absolutePath, ?string $loansCsvPath = null, ?int $graceCycles = null): array
    {
        $this->simulationMode = true;
        $this->simulatedOccupiedPeriods = [];
        $this->simulatedOutputRows = [];
        $this->loanIndexForImport = filled($loansCsvPath) && is_readable($loansCsvPath)
            ? LegacyMigrationCsvLoanIndex::fromPath(
                $loansCsvPath,
                $graceCycles ?? LegacyMigrationGraceCycleSettings::graceCycles(),
            )
            : null;

        try {
            $result = AccountingService::withoutMemberCashCollection(
                fn (): array => ContributionService::withoutPostedNotifications(
                    fn (): array => ContributionService::withoutLiveCollectionGuards(
                        fn(): array => $this->importRows($absolutePath, false),
                    ),
                ),
            );

            return [
                ...$result,
                'rows' => $this->simulatedOutputRows,
            ];
        } finally {
            $this->simulationMode = false;
            $this->simulatedOccupiedPeriods = [];
            $this->simulatedOutputRows = [];
            $this->loanIndexForImport = null;
            $this->importInstallmentTracker = null;
        }
    }

    /**
     * @return array{contributions: int, future_contributions: int, loan_repayments: int, ignored: int, failed: int, reclassified_as_contribution: int, errors: list<string>}
     */
    private function importRows(string $absolutePath, bool $rebuildBalances = true): array
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
        $loanRepayments = 0;
        $failed = 0;
        $reclassifiedAsContribution = 0;
        $errors = [];
        /** @var list<int> $affectedLoanIds */
        $affectedLoanIds = [];
        /** @var list<array{index: int, line: int, row: array<string, string>, type: string}> $deferredRows */
        $deferredRows = [];
        /** @var array<string, float> $cumulativeRepaidByLoanKey */
        $cumulativeRepaidByLoanKey = [];
        $this->importInstallmentTracker = app(LegacyLoanRepaymentInstallmentTracker::class);
        $lineBase = 2;

        $isCanonical = self::isCanonicalClassifiedPaymentsPath($absolutePath);
        $this->trustClassifiedBlueprint = $isCanonical || $this->isClassifiedPaymentsFile($rows);

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                $type = strtolower($this->cell($row, 'payment_type'));

                if ($type === '' && $this->simulationMode) {
                    $deferredRows[] = [
                        'index' => $index,
                        'line' => $lineNumber,
                        'row' => $row,
                        'type' => '',
                    ];

                    continue;
                }

                if (in_array($type, ['ignore', 'skipped', 'skip'], true)) {
                    if ($this->importSkippedRowAsContribution($row, $affectedLoanIds, $cumulativeRepaidByLoanKey, $loanRepayments)) {
                        $contributions++;
                    }

                    continue;
                }

                if (in_array($type, ['contribution', 'loan_repayment', 'loan', 'repayment'], true)) {
                    $deferredRows[] = [
                        'index' => $index,
                        'line' => $lineNumber,
                        'row' => $row,
                        'type' => $type,
                    ];

                    continue;
                }

                if ($type === 'unclassified') {
                    throw new InvalidArgumentException(__('Row is still unclassified — re-run payment classification or set payment_type to contribution or loan_repayment.'));
                }

                throw new InvalidArgumentException(__('Unknown payment_type: :type', ['type' => $type]));
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        usort($deferredRows, function (array $left, array $right): int {
            $leftDate = $this->parseOptionalDateTime($this->cell($left['row'], 'payment_date')) ?? Carbon::minValue();
            $rightDate = $this->parseOptionalDateTime($this->cell($right['row'], 'payment_date')) ?? Carbon::minValue();

            $comparison = $leftDate->timestamp <=> $rightDate->timestamp;

            return $comparison !== 0 ? $comparison : ($left['line'] <=> $right['line']);
        });

        foreach ($deferredRows as $item) {
            try {
                $itemType = $item['type'] !== ''
                    ? $item['type']
                    : $this->inferPaymentType($item['row'], $cumulativeRepaidByLoanKey);

                if (in_array($itemType, ['loan_repayment', 'loan', 'repayment'], true)) {
                    $this->processDeferredLoanRepaymentRow(
                        [
                            ...$item,
                            'type' => $itemType,
                        ],
                        $rows,
                        $affectedLoanIds,
                        $cumulativeRepaidByLoanKey,
                        $contributions,
                        $loanRepayments,
                        $reclassifiedAsContribution,
                    );

                    continue;
                }

                if ($this->importContributionRow($item['row'], $affectedLoanIds, $cumulativeRepaidByLoanKey, $loanRepayments)) {
                    $contributions++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$item['line']}: {$e->getMessage()}";
            }
        }

        if ($reclassifiedAsContribution > 0 && ! $this->simulationMode) {
            $this->persistImportOutcomeRows($absolutePath, $rows, allowCanonical: true);
        }

        if ($affectedLoanIds !== [] && ! $this->simulationMode) {
            $this->scheduleSync->syncLoans($affectedLoanIds);
            $this->waiveLateFeesOnImportedLoans($affectedLoanIds);
            $this->zeroBalanceLoanCompletion->completeAll();
        }

        if ($rebuildBalances && !$this->simulationMode) {
            $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines(reconcileLineBalances: false);
        }

        return [
            'contributions' => $contributions,
            'future_contributions' => 0,
            'loan_repayments' => $loanRepayments,
            'ignored' => 0,
            'failed' => $failed,
            'reclassified_as_contribution' => $reclassifiedAsContribution,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return bool Whether a new ledger row was posted for this CSV row.
     */
    private function importSkippedRowAsContribution(
        array $row,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
        int &$loanRepayments,
    ): bool {
        $notes = $this->cell($row, 'notes');

        return $this->importContributionRow(
            array_merge($row, [
                'payment_type' => 'contribution',
                'notes' => $notes !== '' ? $notes : __('Skipped legacy payment'),
            ]),
            $affectedLoanIds,
            $cumulativeRepaidByLoanKey,
            $loanRepayments,
        );
    }

    /**
     * @param  array{index: int, line: int, row: array<string, string>, type: string}  $item
     * @param  array<int, array<string, string>>  $rows
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function processDeferredLoanRepaymentRow(
        array $item,
        array &$rows,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
        int &$contributions,
        int &$loanRepayments,
        int &$reclassifiedAsContribution,
    ): void {
        $contributionRemainder = 0.0;
        $outcome = $this->importLoanRepaymentRow(
            $item['row'],
            $affectedLoanIds,
            $cumulativeRepaidByLoanKey,
            $contributionRemainder,
        );

        if ($outcome === 'no_loan') {
            $notes = $this->cell($item['row'], 'notes');

            if ($notes === '') {
                $notes = __('Reclassified from loan repayment — no matching loan');
            }

            $reclassifiedRow = $this->reclassifyRowAsContribution(array_merge($item['row'], [
                'notes' => $notes,
            ]));
            $rows[$item['index']] = $reclassifiedRow;
            $reclassifiedAsContribution++;

            if ($this->importContributionRow($reclassifiedRow, $affectedLoanIds, $cumulativeRepaidByLoanKey, $loanRepayments, reclassified: true)) {
                $contributions++;
            }

            return;
        }

        if ($outcome === 'repayment') {
            $loanRepayments++;
        }
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return bool Whether a new ledger row was posted for this CSV row.
     */
    private function importContributionRow(
        array $row,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
        int &$loanRepayments,
        bool $reclassified = false,
    ): bool {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $periodRaw = $this->cell($row, 'period');
        $paymentDate = $this->cell($row, 'payment_date');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');

        $member = $this->resolveMemberForImportRow($email, $number);

        if ($member === null) {
            throw new InvalidArgumentException(__('Member not found for contribution row.'));
        }

        $postedAt = $this->parseOptionalDateTime($paymentDate) ?? BusinessDay::now();
        $baseNotes = $this->cell($row, 'notes') ?: __('Legacy migration contribution');

        if ($this->legacyImportRowAlreadyPosted($member, $row)) {
            if ($this->simulationMode) {
                $this->recordSimulatedOutputRow($member, $row, 'contribution');
            }

            return false;
        }

        if ($amount <= 0.00001) {
            return false;
        }

        [$month, $year] = $this->resolveContributionPeriod($periodRaw, $paymentDate);
        $notes = $this->appendLegacyImportFingerprint($baseNotes, $row);

        if (! $this->memberPeriodOccupied((int) $member->id, $month, $year)) {
            $this->postLegacyContribution($member, $month, $year, $amount, $postedAt, $notes);
        } else {
            if (!$this->simulationMode && $this->legacyContributionRowAlreadyImported($member, $month, $year, $amount, $postedAt)) {
                return false;
            }

            $this->mergeLegacyContributionTopUp(
                $member,
                $month,
                $year,
                $amount,
                $postedAt,
                $notes,
            );
        }

        if ($this->simulationMode) {
            $this->recordSimulatedOutputRow(
                $member,
                $row,
                $reclassified ? 'reclassified_contribution' : 'contribution',
                amount: $amount,
                period: sprintf('%04d-%02d', $year, $month),
            );
        }

        return true;
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

            return $this->cycles->cyclePeriodForDueDate(
                $this->parseOptionalDateTime($paymentDate) ?? BusinessDay::now(),
            );
        }

        if (preg_match('/^\d{4}-\d{2}$/', $periodRaw) === 1) {
            $periodRaw .= '-01';
        }

        $date = Carbon::parse($periodRaw)->startOfMonth();

        return [(int) $date->month, (int) $date->year];
    }

    private function postLegacyContribution(
        Member $member,
        int $month,
        int $year,
        float $amount,
        Carbon $postedAt,
        string $notes,
    ): void {
        if ($this->simulationMode) {
            $this->simulatedOccupiedPeriods[$this->periodKey((int) $member->id, $month, $year)] = true;

            return;
        }

        $this->occupiedPeriodCache[$this->periodKey((int) $member->id, $month, $year)] = true;

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
     * @param  array<int, array<string, string>>  $rows
     */
    private function precheckAndReclassifyUnresolvedRepayments(array &$rows, array $cumulativeRepaidByLoanKey): int
    {
        $reclassified = 0;

        foreach ($rows as $index => $row) {
            $type = strtolower($this->cell($row, 'payment_type'));

            if (! in_array($type, ['loan_repayment', 'loan', 'repayment'], true)) {
                continue;
            }

            if ($this->resolveExplicitLoan($row) !== null) {
                continue;
            }

            $rows[$index] = $this->reclassifyRowAsContribution($row);
            $reclassified++;
        }

        return $reclassified;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return 'repayment'|'no_loan'|'skipped'
     */
    private function importLoanRepaymentRow(
        array $row,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
        float &$contributionRemainder = 0.0,
    ): string {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'payment_date')) ?? BusinessDay::now();
        $notes = $this->appendLegacyImportFingerprint(
            $this->cell($row, 'notes') ?: __('Legacy migration loan repayment'),
            $row,
        );

        $member = $this->resolveMemberForImportRow($email, $number);

        if ($member === null) {
            throw new InvalidArgumentException(__('Member not found for loan repayment row.'));
        }

        $explicitLoan = $this->resolveExplicitLoan($row);

        if ($this->trustClassifiedBlueprint && $explicitLoan !== null) {
            return $this->importExplicitBlueprintLoanRepayment(
                $row,
                $member,
                $explicitLoan,
                $amount,
                $paidAt,
                $notes,
                $affectedLoanIds,
                $cumulativeRepaidByLoanKey,
                $contributionRemainder,
            );
        }

        if ($explicitLoan === null) {
            return 'no_loan';
        }

        if ((int) $explicitLoan->member_id !== (int) $member->id) {
            return 'no_loan';
        }

        if (!in_array($explicitLoan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            throw new InvalidArgumentException(__('Loan must be active or settled to receive imported repayments.'));
        }

        $contributionRemainder = 0.0;

        if ($this->legacyImportRowAlreadyPosted($member, $row)) {
            $this->repaymentWindowResolver->recordRepayment($explicitLoan, $member, $amount, $cumulativeRepaidByLoanKey);

            return 'skipped';
        }

        $this->postAllocatedLoanRepayment(
            $explicitLoan,
            $amount,
            $paidAt,
            $notes,
            $affectedLoanIds,
            $cumulativeRepaidByLoanKey,
        );

        return 'repayment';
    }

    /**
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function postAllocatedLoanRepayment(
        Loan $loan,
        float $amount,
        Carbon $paidAt,
        string $notes,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
    ): void {
        if ($this->simulationMode) {
            $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);
            $this->recordSimulatedOutputRow(
                $loan->member,
                [
                    'member_email' => (string) $loan->member->email,
                    'member_number' => (string) $loan->member->member_number,
                    'payment_date' => $paidAt->toDateString(),
                    'amount' => (string) round($amount, 2),
                    'payment_type' => 'loan_repayment',
                    'loan_number' => (string) $loan->id,
                    'period' => '',
                    'notes' => $notes,
                ],
                'loan_repayment',
                loan: $loan,
                amount: $amount,
            );

            return;
        }

        if (LoanRepayment::query()->where('notes', $notes)->exists()) {
            $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);

            return;
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
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveExplicitLoan(array $row): ?Loan
    {
        $loanNumber = $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number');

        if ($loanNumber === '' || ! is_numeric($loanNumber)) {
            return null;
        }

        return Loan::query()->find((int) $loanNumber);
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
            [$month, $year] = $this->cycles->cyclePeriodForDueDate(
                LegacyMigrationDateParser::parseValue($paymentDate),
            );
            $period = sprintf('%04d-%02d', $year, $month);
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
     * @param  array<string, string>  $row
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     * @return 'repayment'|'no_loan'|'split'
     */
    private function importExplicitBlueprintLoanRepayment(
        array $row,
        Member $member,
        Loan $explicitLoan,
        float $amount,
        Carbon $paidAt,
        string $notes,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
        float &$contributionRemainder,
    ): string {
        if ((int) $explicitLoan->member_id !== (int) $member->id) {
            return 'no_loan';
        }

        if (!in_array($explicitLoan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            throw new InvalidArgumentException(__('Loan must be active or settled to receive imported repayments.'));
        }

        $window = $this->repaymentWindowResolver->windowForLoan($member, $explicitLoan);
        $this->importInstallmentTracker?->registerWindow($window);
        $contributionRemainder = 0.0;

        if ($amount <= 0.00001) {
            return 'no_loan';
        }

        if ($this->legacyImportRowAlreadyPosted($member, $row)) {
            $this->repaymentWindowResolver->recordRepayment($explicitLoan, $member, $amount, $cumulativeRepaidByLoanKey);
            $this->importInstallmentTracker?->applyRepayment($window->loanKey, $amount);

            return 'skipped';
        }

        $this->postAllocatedLoanRepayment(
            $explicitLoan,
            $amount,
            $paidAt,
            $notes,
            $affectedLoanIds,
            $cumulativeRepaidByLoanKey,
        );
        $this->importInstallmentTracker?->applyRepayment($window->loanKey, $amount);

        return 'repayment';
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function persistImportOutcomeRows(string $absolutePath, array $rows, bool $allowCanonical = false): void
    {
        if (self::isCanonicalClassifiedPaymentsPath($absolutePath) && !$allowCanonical) {
            return;
        }

        $this->writeNormalizedImportRows($absolutePath, $rows);
    }

    public static function isCanonicalClassifiedPaymentsPath(string $absolutePath): bool
    {
        $canonicalPath = LegacyPaymentClassifierService::classifiedPaymentsAbsolutePath();

        if ($canonicalPath === null) {
            return false;
        }

        if ($absolutePath === $canonicalPath) {
            return true;
        }

        $resolvedCanonical = realpath($canonicalPath);
        $resolvedPath = realpath($absolutePath);

        return $resolvedCanonical !== false
            && $resolvedPath !== false
            && $resolvedCanonical === $resolvedPath;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function writeNormalizedImportRows(string $absolutePath, array $rows): void
    {
        $normalized = array_map(fn (array $row): array => [
            'member_email' => $this->cell($row, 'member_email'),
            'member_number' => $this->cell($row, 'member_number'),
            'payment_date' => $this->cell($row, 'payment_date'),
            'amount' => $this->cell($row, 'amount'),
            'payment_type' => $this->cell($row, 'payment_type'),
            'loan_number' => $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number'),
            'period' => $this->cell($row, 'period'),
            'migration_outcome' => in_array(strtolower($this->cell($row, 'payment_type')), ['loan_repayment', 'loan', 'repayment'], true)
                ? 'loan_repayment'
                : 'contribution',
            'notes' => $this->cell($row, 'notes'),
        ], array_values($rows));

        $this->classifier->writeClassifiedCsv($absolutePath, $normalized);
    }

    /**
     * @deprecated Use {@see persistImportOutcomeRows()} — kept for non-canonical test fixtures only.
     *
     * @param  array<int, array<string, string>>  $rows
     */
    private function persistClassifiedRows(string $absolutePath, array $rows): void
    {
        $this->persistImportOutcomeRows($absolutePath, $rows);
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
            $parsed = LegacyMigrationDateParser::parseValue($value);

            $maxSupportedDate = Carbon::parse(self::MAX_SUPPORTED_PAYMENT_DATE);
            if ($parsed->gt($maxSupportedDate)) {
                throw new InvalidArgumentException(__('Payment date :date is beyond supported import range (max :max).', [
                    'date' => $parsed->toDateString(),
                    'max' => $maxSupportedDate->toDateString(),
                ]));
            }

            return $parsed;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException(__('Invalid date/time: :value', ['value' => $value]));
        }
    }

    private function legacyLoanRepaymentAlreadyImported(Loan $loan, float $amount, Carbon $paidAt): bool
    {
        if ($this->simulationMode) {
            return false;
        }

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
        if ($this->simulationMode) {
            return false;
        }

        if ($this->ledgerContainsImportFingerprint($member, $this->legacyImportFingerprint($row))) {
            return true;
        }

        $sourceNotes = $this->cell($row, 'notes');

        if ($sourceNotes !== '') {
            return $this->ledgerContainsSourceNotes($member, $sourceNotes);
        }

        return $this->ledgerContainsImportFingerprint($member, $this->legacyImportFingerprint($row, legacy: true));
    }

    private function ledgerContainsSourceNotes(Member $member, string $sourceNotes): bool
    {
        if (
            Contribution::query()
                ->where('member_id', $member->id)
                ->where('notes', 'like', '%' . $sourceNotes . '%')
                ->where('notes', 'like', '%legacy-import:%')
                ->exists()
        ) {
            return true;
        }

        if (
            Transaction::query()
                ->where('member_id', $member->id)
                ->where('description', 'like', '%' . $sourceNotes . '%')
                ->where('description', 'like', '%legacy-import:%')
                ->exists()
        ) {
            return true;
        }

        return LoanRepayment::query()
            ->whereHas('loan', fn($query) => $query->where('member_id', $member->id))
            ->where('notes', 'like', '%' . $sourceNotes . '%')
            ->where('notes', 'like', '%legacy-import:%')
            ->exists();
    }

    private function ledgerContainsImportFingerprint(Member $member, string $fingerprint): bool
    {
        if (
            Contribution::query()
                ->where('member_id', $member->id)
                ->where('notes', 'like', '%' . $fingerprint . '%')
                ->exists()
        ) {
            return true;
        }

        if (
            Transaction::query()
                ->where('member_id', $member->id)
                ->where('description', 'like', '%' . $fingerprint . '%')
                ->exists()
        ) {
            return true;
        }

        return LoanRepayment::query()
            ->whereHas('loan', fn($query) => $query->where('member_id', $member->id))
            ->where('notes', 'like', '%' . $fingerprint . '%')
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
    private function legacyImportFingerprint(array $row, bool $legacy = false): string
    {
        $parts = [
            $this->cell($row, 'member_number'),
            $this->cell($row, 'member_email'),
            $this->cell($row, 'payment_date'),
            $this->cell($row, 'amount'),
            $this->cell($row, 'payment_type'),
            $this->cell($row, 'period'),
        ];

        if (!$legacy) {
            $parts[] = $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number');
            $parts[] = $this->cell($row, 'notes');
        }

        return 'legacy-import:' . implode('|', $parts);
    }

    private function resolveMemberForImportRow(string $email, string $memberNumber): ?Member
    {
        $cacheKey = $memberNumber !== '' ? 'n:' . $memberNumber : 'e:' . $email;

        if (array_key_exists($cacheKey, $this->memberImportCache)) {
            return $this->memberImportCache[$cacheKey];
        }

        if ($memberNumber !== '') {
            $member = Member::query()->where('member_number', $memberNumber)->first();
            $this->memberImportCache[$cacheKey] = $member;

            return $member;
        }

        if ($email !== '') {
            $member = Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $this->memberImportCache[$cacheKey] = $member;

            return $member;
        }

        $this->memberImportCache[$cacheKey] = null;

        return null;
    }

    /**
     * @param  list<int>  $affectedLoanIds
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    public function postAllocatedLoanRepaymentForRepair(
        Loan $loan,
        float $amount,
        Carbon $paidAt,
        string $notes,
        array &$affectedLoanIds,
        array &$cumulativeRepaidByLoanKey,
    ): bool {
        if ($this->legacyLoanRepaymentAlreadyImported($loan, $amount, $paidAt)) {
            $this->repaymentWindowResolver->recordRepayment($loan, $loan->member, $amount, $cumulativeRepaidByLoanKey);

            return false;
        }

        $this->postAllocatedLoanRepayment(
            $loan,
            $amount,
            $paidAt,
            $notes,
            $affectedLoanIds,
            $cumulativeRepaidByLoanKey,
        );

        return true;
    }

    public function postLegacyContributionForRepair(
        Member $member,
        int $month,
        int $year,
        float $amount,
        Carbon $postedAt,
        string $notes,
    ): void {
        if ($this->memberPeriodOccupied((int) $member->id, $month, $year)) {
            $this->mergeLegacyContributionTopUp($member, $month, $year, $amount, $postedAt, $notes);

            return;
        }

        $this->postLegacyContribution($member, $month, $year, $amount, $postedAt, $notes);
    }

    /**
     * Add an extra legacy payment into an existing contribution period (cash-in then fund post).
     */
    public function mergeLegacyContributionTopUp(
        Member $member,
        int $month,
        int $year,
        float $amount,
        Carbon $postedAt,
        string $notes,
        bool $creditCashFirst = true,
    ): void {
        if ($amount <= 0.00001) {
            return;
        }

        if ($this->simulationMode) {
            return;
        }

        $contribution = Contribution::findForMemberPeriod($member->id, $month, $year);

        if ($contribution === null) {
            throw new InvalidArgumentException(__('No contribution exists for :period to merge this legacy payment into.', [
                'period' => Carbon::create($year, $month, 1)->format('M Y'),
            ]));
        }

        DB::transaction(function () use ($member, $contribution, $month, $year, $amount, $postedAt, $notes, $creditCashFirst): void {
            $periodLabel = Carbon::create($year, $month, 1)->format('M Y');
            $description = __('Contribution — :period', ['period' => $periodLabel]);

            if ($creditCashFirst) {
                AccountingService::withoutMemberCashCollection(function () use ($member, $amount, $description, $contribution, $postedAt): void {
                    $this->accounting->creditMemberCashWithMasterMirror(
                        $member->cashAccount,
                        $amount,
                        $description,
                        __('(legacy contribution merge cash-in mirror)'),
                        $contribution,
                        $postedAt,
                        $member->id,
                    );
                });
            }

            $newAmount = round((float) $contribution->amount + $amount, 2);
            $newDue = round((float) ($contribution->amount_due ?? $contribution->amount) + $amount, 2);
            $newCollected = round((float) ($contribution->amount_collected ?? 0) + $amount, 2);
            $mergedNotes = trim(($contribution->notes ?? '') . ($notes !== '' ? "\n" . $notes : ''));

            $contribution->update([
                'amount' => $newAmount,
                'amount_due' => $newDue,
                'amount_collected' => $newCollected,
                'notes' => $mergedNotes !== '' ? $mergedNotes : $contribution->notes,
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'status' => $contribution->status === 'pending' ? 'pending' : 'posted',
                'posted_at' => $contribution->posted_at ?? $postedAt,
                'paid_at' => $contribution->paid_at ?? $postedAt,
            ]);

            $this->accounting->postContributionPrincipal($contribution->fresh(), $amount, $postedAt);

            if ($contribution->fresh()->status === 'pending') {
                $contribution->refresh()->update([
                    'status' => 'posted',
                    'posted_at' => $postedAt,
                    'paid_at' => $contribution->paid_at ?? $postedAt,
                ]);
            }
        });
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function isClassifiedPaymentsFile(array $rows): bool
    {
        foreach ($rows as $row) {
            $type = strtolower($this->cell($row, 'payment_type'));

            if (!in_array($type, ['contribution', 'loan_repayment', 'loan', 'repayment', 'ignore', 'skipped', 'skip'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, float>  $cumulativeRepaidByLoanKey
     */
    private function inferPaymentType(array $row, array $cumulativeRepaidByLoanKey): string
    {
        $explicit = strtolower($this->cell($row, 'payment_type'));

        if ($explicit !== '') {
            return $explicit;
        }

        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $member = $this->resolveMemberForImportRow($email, $number);

        if ($member === null) {
            throw new InvalidArgumentException(__('Member not found for payment row.'));
        }

        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'payment_date')) ?? BusinessDay::now();
        $classifyMember = LegacyPaymentClassifyMember::fromDatabase($member);
        $windows = $this->loanSchedule->forMember($classifyMember, $this->loanIndexForImport);
        $allocation = $this->chronology->allocate($paidAt, $amount, $windows, $cumulativeRepaidByLoanKey);

        return $allocation->primaryType();
    }

    private function memberPeriodOccupied(int $memberId, int $month, int $year): bool
    {
        $key = $this->periodKey($memberId, $month, $year);

        if ($this->simulationMode) {
            return isset($this->simulatedOccupiedPeriods[$key]);
        }

        if (array_key_exists($key, $this->occupiedPeriodCache)) {
            return $this->occupiedPeriodCache[$key];
        }

        $occupied = Contribution::memberPeriodRecordExists($memberId, $month, $year);
        $this->occupiedPeriodCache[$key] = $occupied;

        return $occupied;
    }

    private function periodKey(int $memberId, int $month, int $year): string
    {
        return "{$memberId}:{$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function recordSimulatedOutputRow(
        Member $member,
        array $row,
        string $migrationOutcome,
        ?Loan $loan = null,
        ?float $amount = null,
        ?string $period = null,
    ): void {
        $paymentType = $migrationOutcome === 'loan_repayment' ? 'loan_repayment' : 'contribution';
        $resolvedAmount = $amount ?? $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paymentDate = $this->cell($row, 'payment_date');

        if ($paymentDate === '') {
            $paymentDate = BusinessDay::now()->toDateString();
        } elseif ($this->parseOptionalDateTime($paymentDate) !== null) {
            $paymentDate = $this->parseOptionalDateTime($paymentDate)->toDateString();
        }

        $resolvedPeriod = $period ?? $this->cell($row, 'period');

        if ($resolvedPeriod === '' && $paymentType === 'contribution' && $paymentDate !== '') {
            [$month, $year] = $this->cycles->cyclePeriodForDueDate(
                $this->parseOptionalDateTime($paymentDate) ?? BusinessDay::now(),
            );
            $resolvedPeriod = sprintf('%04d-%02d', $year, $month);
        }

        $this->simulatedOutputRows[] = [
            'member_email' => (string) $member->email,
            'member_number' => (string) $member->member_number,
            'payment_date' => $paymentDate,
            'amount' => (string) round($resolvedAmount, 2),
            'payment_type' => $paymentType,
            'loan_number' => $loan !== null ? (string) $loan->id : $this->cell($row, 'loan_number'),
            'period' => $resolvedPeriod,
            'migration_outcome' => $migrationOutcome,
            'notes' => $this->cell($row, 'notes'),
        ];
    }

    /**
     * @param  list<int>  $loanIds
     */
    private function waiveLateFeesOnImportedLoans(array $loanIds): void
    {
        foreach (array_unique($loanIds) as $loanId) {
            if (! LegacyImportedLoan::isLoan((int) $loanId)) {
                continue;
            }

            LegacyImportedLoan::waiveAutomatedLateFees((int) $loanId);
        }
    }
}
