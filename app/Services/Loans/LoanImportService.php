<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\LegacyMigration\LegacyMigrationLoanFundingSimulator;
use App\Services\MemberCashOutService;
use App\Support\AssociativeCsv;
use App\Support\BusinessDay;
use App\Support\LegacyLoanCsvIdentity;
use App\Support\LegacyMemberIdentifierResolver;
use App\Support\LoanFundingStrategy;
use App\Support\LoanRepaymentWindowPolicy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class LoanImportService
{
    private ?int $graceCyclesForImport = null;

    private ?string $fundingStrategyForImport = null;

    private ?LegacyMigrationLoanFundingSimulator $fundingSimulator = null;

    private ?bool $skipSettlementThresholdForImport = null;

    private ?int $maxLegacyLoanIdUsed = null;

    public function __construct(
        private readonly LoanLedgerService $ledger,
        private readonly LegacyMemberIdentifierResolver $memberResolver,
        private readonly MemberCashOutService $cashOuts,
    ) {}

    /**
     * Import loans from a UTF-8 CSV with a header row.
     *
     * @return array{created: int, failed: int, errors: array<int, string>}
     */
    public function import(
        string $absolutePath,
        ?int $graceCycles = null,
        ?string $fundingStrategy = null,
        ?string $paymentsCsvForFunding = null,
        ?bool $skipSettlementThreshold = null,
    ): array {
        $this->authorizeImport();

        $graceCycles = LoanSettings::clampGraceCycles($graceCycles ?? 1);
        $this->fundingStrategyForImport = $fundingStrategy !== null
            ? LoanFundingStrategy::normalize($fundingStrategy)
            : null;
        $this->skipSettlementThresholdForImport = $skipSettlementThreshold;

        if (
            $this->fundingStrategyForImport === LoanFundingStrategy::MEMBER_FUND_TOPUP
            && filled($paymentsCsvForFunding)
            && is_readable($paymentsCsvForFunding)
        ) {
            $this->fundingSimulator = LegacyMigrationLoanFundingSimulator::forLegacyMigration($paymentsCsvForFunding);
        }

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

        if ($this->fundingStrategyForImport === LoanFundingStrategy::MEMBER_FUND_TOPUP) {
            $rows = $this->sortRowsByDisbursedAt($rows);
        }

        $lineBase = 2;

        $this->graceCyclesForImport = $graceCycles;

        try {
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
        } finally {
            if ($this->maxLegacyLoanIdUsed !== null) {
                $this->syncLoanPrimaryKeySequence($this->maxLegacyLoanIdUsed);
                $this->maxLegacyLoanIdUsed = null;
            }

            $this->graceCyclesForImport = null;
            $this->fundingStrategyForImport = null;
            $this->fundingSimulator = null;
            $this->skipSettlementThresholdForImport = null;
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
            throw new AuthorizationException(__('You must be signed in to import loans.'));
        }

        if ($user->is_admin) {
            return;
        }

        throw new AuthorizationException(__('You do not have permission to import loans.'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(array $row): void
    {
        $member = $this->resolveMember($row);
        $guarantorMemberId = $this->resolveOptionalGuarantor($row, $member)?->id;
        $loanStatus = $this->parseLoanStatus($this->cell($row, 'loan_status'));

        match ($loanStatus) {
            'pending' => $this->importPending($row, $member, $guarantorMemberId),
            'approved' => $this->importApproved($row, $member, $guarantorMemberId),
            'active' => $this->importDisbursed($row, $member, 'active', false, $guarantorMemberId),
            'completed' => $this->importDisbursed($row, $member, 'completed', true, $guarantorMemberId),
            'early_settled' => $this->importDisbursed($row, $member, 'early_settled', true, $guarantorMemberId),
            default => throw new \InvalidArgumentException("Unsupported loan_status: {$loanStatus}"),
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importPending(array $row, Member $member, ?int $guarantorMemberId): void
    {
        $amountRequested = $this->parseAmountRequestedForPending($row);
        $amountApproved = $this->parseOptionalMoney($this->cell($row, 'amount_approved'), 'amount_approved');

        $purpose = $this->cell($row, 'purpose');
        if ($purpose === '') {
            $purpose = __('Imported loan');
        }

        $isEmergency = $this->parseBool($this->cell($row, 'is_emergency'));
        $loanTier = $this->resolveLoanTierOptional($row, $amountRequested, $isEmergency);
        $installmentsCount = $this->parseOptionalPositiveInt($this->cell($row, 'installments_count'), 12);
        $appliedAt = $this->parseOptionalDateTime($this->cell($row, 'applied_at')) ?? BusinessDay::now();

        $this->createLoanRecord([
            'member_id' => $member->id,
            'loan_tier_id' => $loanTier?->id,
            'fund_tier_id' => null,
            'queue_position' => null,
            ...$this->baseLoanAttributes($amountRequested, $installmentsCount),
            'amount_requested' => $amountRequested,
            'amount_approved' => $amountApproved,
            'purpose' => $purpose,
            'installments_count' => $installmentsCount,
            'status' => 'pending',
            'applied_at' => $appliedAt,
            'is_emergency' => $isEmergency,
            'guarantor_member_id' => $guarantorMemberId,
        ], $this->parseOptionalLegacyLoanId($row));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importApproved(array $row, Member $member, ?int $guarantorMemberId): void
    {
        $amount = $this->parseMoney($this->cell($row, 'amount_approved'), 'amount_approved');
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('amount_approved must be positive.'));
        }

        $purpose = $this->cell($row, 'purpose');
        if ($purpose === '') {
            $purpose = __('Imported loan');
        }

        $isEmergency = $this->parseBool($this->cell($row, 'is_emergency'));
        $loanTier = $this->resolveLoanTier($row, $amount, $isEmergency);
        $fundTier = $this->resolveFundTier($row, $loanTier, $isEmergency);
        $threshold = $this->parseThreshold($this->cell($row, 'settlement_threshold'));
        $minInstall = (float) ($loanTier?->min_monthly_installment ?? 1000);
        $count = $this->resolveInstallmentsCountForApproved($row, $amount, $member, $minInstall, $threshold);
        $amountRequested = $this->parseOptionalMoney($this->cell($row, 'amount_requested'), 'amount_requested') ?? $amount;
        $approvedAt = $this->parseOptionalDateTime($this->cell($row, 'approved_at')) ?? BusinessDay::now();
        $appliedAt = $this->parseOptionalDateTime($this->cell($row, 'applied_at')) ?? $approvedAt;

        $this->createLoanRecord([
            'member_id' => $member->id,
            'loan_tier_id' => $loanTier?->id,
            'fund_tier_id' => $fundTier->id,
            'queue_position' => null,
            ...$this->baseLoanAttributes($amount, $count),
            'amount_requested' => $amountRequested,
            'amount_approved' => $amount,
            'purpose' => $purpose,
            'installments_count' => $count,
            'status' => 'approved',
            'applied_at' => $appliedAt,
            'approved_at' => $approvedAt,
            'approved_by_id' => auth('tenant')->id(),
            'settlement_threshold' => $threshold,
            'is_emergency' => $isEmergency,
            'guarantor_member_id' => $guarantorMemberId,
        ], $this->parseOptionalLegacyLoanId($row));

        LoanQueueOrderingService::resequenceFundTier($fundTier->id);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importDisbursed(
        array $row,
        Member $member,
        string $terminalStatus,
        bool $allPaid,
        ?int $guarantorMemberId,
    ): void {
        $amount = $this->parseMoney($this->cell($row, 'amount_approved'), 'amount_approved');
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('amount_approved must be positive.'));
        }

        $disbursedAt = $this->parseDisbursedAt($this->cell($row, 'disbursed_at'));

        [$memberPortion, $masterPortion, $portionsExplicit] = $this->resolvePortions(
            $row,
            $amount,
            $member,
            $disbursedAt,
        );

        $isEmergency = $this->parseBool($this->cell($row, 'is_emergency'));
        $loanTier = $this->resolveLoanTier($row, $amount, $isEmergency);
        $fundTier = $this->resolveFundTier($row, $loanTier, $isEmergency);
        $threshold = $this->parseThreshold($this->cell($row, 'settlement_threshold'));
        $minInstall = (float) ($loanTier?->min_monthly_installment ?? 1000);
        $count = $this->resolveInstallmentsCountDisbursed(
            $row,
            $amount,
            $memberPortion,
            $masterPortion,
            $minInstall,
            $threshold,
            $portionsExplicit,
        );
        $paidCount = $allPaid ? $count : $this->parsePaidInstallmentsCount($row, $count);
        $graceCycles = $this->graceCyclesForImport ?? 1;
        $exemption = Loan::computeExemptionAndFirstRepayment($disbursedAt, $graceCycles);
        $exemption = Loan::finalizeExemptionForDisbursement($member, $exemption, $disbursedAt);

        $purpose = $this->cell($row, 'purpose');
        if ($purpose === '') {
            $purpose = __('Imported loan');
        }

        $totalRepaidCell = $this->cell($row, 'total_amount_repaid');
        if ($totalRepaidCell !== '') {
            $totalRepaid = round($this->parseMoney($totalRepaidCell, 'total_amount_repaid'), 2);
            if ($totalRepaid < 0) {
                throw new \InvalidArgumentException(__('total_amount_repaid cannot be negative.'));
            }
        } else {
            $totalRepaid = round($paidCount * $minInstall, 2);
        }

        if ($allPaid && $totalRepaid <= 0) {
            throw new \InvalidArgumentException(
                __('completed / early_settled loans need a positive repayment total (set total_amount_repaid or use installments_count × min monthly installment).')
            );
        }

        $amountRequested = $this->parseOptionalMoney($this->cell($row, 'amount_requested'), 'amount_requested') ?? $amount;
        $settledAt = ($terminalStatus === 'active')
            ? null
            : ($this->parseOptionalDateTime($this->cell($row, 'settled_at')) ?? $disbursedAt);
        $appliedAt = $this->parseOptionalDateTime($this->cell($row, 'applied_at')) ?? $disbursedAt;
        $approvedAt = $this->parseOptionalDateTime($this->cell($row, 'approved_at')) ?? $disbursedAt;

        $fundingStrategy = $this->fundingStrategyForImport;
        $legacyLoanId = $this->parseOptionalLegacyLoanId($row);

        DB::transaction(function () use ($member, $loanTier, $fundTier, $amount, $amountRequested, $purpose, $count, $disbursedAt, $exemption, $threshold, $isEmergency, $memberPortion, $masterPortion, $paidCount, $minInstall, $totalRepaid, $terminalStatus, $settledAt, $appliedAt, $approvedAt, $guarantorMemberId, $graceCycles, $fundingStrategy, $legacyLoanId): void {
            $totalToRepay = round($masterPortion + ($amount * $threshold), 2);

            $loan = $this->createLoanRecord([
                'member_id' => $member->id,
                'loan_tier_id' => $loanTier?->id,
                'fund_tier_id' => $fundTier->id,
                'queue_position' => null,
                ...$this->baseLoanAttributes($amount, $count),
                'amount_requested' => $amountRequested,
                'amount_approved' => $amount,
                'purpose' => $purpose,
                'installments_count' => $count,
                'status' => 'active',
                'applied_at' => $appliedAt,
                'approved_at' => $approvedAt,
                'approved_by_id' => auth('tenant')->id(),
                'disbursed_at' => $disbursedAt,
                'due_date' => $disbursedAt->copy()->addMonths($count)->toDateString(),
                'exempted_month' => $exemption['exempted_month'],
                'exempted_year' => $exemption['exempted_year'],
                'first_repayment_month' => $exemption['first_repayment_month'],
                'first_repayment_year' => $exemption['first_repayment_year'],
                'grace_cycles' => $graceCycles,
                'has_grace_cycle' => $graceCycles > 0,
                ...(filled($fundingStrategy) ? ['funding_strategy' => $fundingStrategy] : []),
                'settlement_threshold' => $threshold,
                'is_emergency' => $isEmergency,
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'guarantor_member_id' => $guarantorMemberId,
            ], $legacyLoanId);

            $this->ledger->postImportedLoanDisbursementWithPortions(
                $loan,
                $memberPortion,
                $masterPortion,
                $disbursedAt,
                allowNegativeMasterFundBalance: true,
            );
            $loan->refresh();

            $this->cashOuts->submitAndAcceptImportedLoanDisbursement(
                $member,
                (int) $loan->id,
                $amount,
                $disbursedAt,
                auth('tenant')->id(),
            );

            if ($totalRepaid > 0) {
                $this->ledger->postImportedLoanRepayments($loan, $totalRepaid);
                $loan->refresh();
            }

            $policy = app(LoanRepaymentWindowPolicy::class);
            $firstPeriod = Carbon::create(
                $exemption['first_repayment_year'],
                $exemption['first_repayment_month'],
                1,
            );

            for ($i = 1; $i <= $count; $i++) {
                $period = $firstPeriod->copy()->addMonths($i - 1);
                $dueDate = $policy->installmentDueDateForCycle((int) $period->month, (int) $period->year);
                $isPaid = $i <= $paidCount;
                LoanInstallment::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $i,
                    'amount' => Loan::scheduleInstallmentAmount($i, $count, $minInstall, $totalToRepay),
                    'due_date' => $dueDate->toDateString(),
                    'paid_at' => $isPaid ? $dueDate->copy()->startOfDay() : null,
                    'status' => $isPaid ? 'paid' : 'pending',
                ]);
            }

            if ($count === 0) {
                $loan->update([
                    'status' => 'completed',
                    'settled_at' => $disbursedAt,
                    'installments_count' => 0,
                    'term_months' => 0,
                ]);
                $loan->refresh();
                $loan->completeAsFullyMemberFundedLegacyImport($disbursedAt);
                $loan->refresh()->releaseGuarantorIfDue();
            } elseif ($terminalStatus !== 'active') {
                $loan->update([
                    'status' => $terminalStatus,
                    'settled_at' => $settledAt,
                ]);
            }
        });

        $this->fundingSimulator?->recordDisbursement($member, $disbursedAt, $memberPortion);

        LoanQueueOrderingService::resequenceFundTier($fundTier->id);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: float, 1: float, 2: bool}
     */
    private function resolvePortions(array $row, float $amount, Member $member, Carbon $disbursedAt): array
    {
        $memberCell = $this->cell($row, 'member_portion');
        $masterCell = $this->cell($row, 'master_portion');

        if ($memberCell === '' && $masterCell === '') {
            if ($this->fundingStrategyForImport !== null) {
                if ($this->fundingSimulator !== null) {
                    $fundBalance = $this->fundingSimulator->fundBalanceBeforeDisbursement($member, $disbursedAt);
                } else {
                    $fundBalance = (float) (Account::query()
                        ->where('member_id', $member->id)
                        ->where('type', 'fund')
                        ->where('is_master', false)
                        ->value('balance') ?? 0);
                    $fundBalance = max(0.0, $fundBalance);
                }

                $portions = LoanSettings::resolveFundingPortions(
                    $amount,
                    $fundBalance,
                    $this->fundingStrategyForImport,
                );

                return [
                    $portions['member_portion'],
                    $portions['master_portion'],
                    false,
                ];
            }

            // Direct CSV import without migration funding strategy:
            // member fund takes the full disbursement and can go negative.
            $memberPortion = round($amount, 2);
            $masterPortion = 0.0;

            return [$memberPortion, $masterPortion, false];
        } elseif ($memberCell === '') {
            $masterPortion = $this->parseMoney($masterCell, 'master_portion');
            $memberPortion = round($amount - $masterPortion, 2);
        } elseif ($masterCell === '') {
            $memberPortion = $this->parseMoney($memberCell, 'member_portion');
            $masterPortion = round($amount - $memberPortion, 2);
        } else {
            $memberPortion = $this->parseMoney($memberCell, 'member_portion');
            $masterPortion = $this->parseMoney($masterCell, 'master_portion');
        }

        if ($memberPortion < 0 || $masterPortion < 0) {
            throw new \InvalidArgumentException(__('member_portion and master_portion cannot be negative.'));
        }

        if (abs(($memberPortion + $masterPortion) - $amount) > 0.02) {
            throw new \InvalidArgumentException(
                __('member_portion + master_portion must equal amount_approved (within 0.02 SAR).')
            );
        }

        return [$memberPortion, $masterPortion, true];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveMember(array $row): Member
    {
        return $this->resolveMemberFromIdentifiers(
            email: strtolower($this->cell($row, 'member_email')),
            number: $this->cell($row, 'member_number'),
            nationalId: $this->cell($row, 'national_id'),
            name: $this->cell($row, 'member_name') ?: $this->cell($row, 'name'),
            subject: __('borrower'),
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveOptionalGuarantor(array $row, Member $borrower): ?Member
    {
        $email = strtolower($this->cell($row, 'guarantor_member_email') ?: $this->cell($row, 'guarantor_email'));
        $number = $this->cell($row, 'guarantor_member_number') ?: $this->cell($row, 'guarantor_number');
        $name = $this->cell($row, 'guarantor_name');

        if ($email === '' && $number === '' && $name === '') {
            return null;
        }

        $guarantor = $this->resolveMemberFromIdentifiers(
            email: $email,
            number: $number,
            nationalId: '',
            name: $name,
            subject: __('guarantor'),
        );

        if ((int) $guarantor->id === (int) $borrower->id) {
            throw new \InvalidArgumentException(__('Guarantor cannot be the same member as the borrower.'));
        }

        return $guarantor;
    }

    private function resolveMemberFromIdentifiers(
        string $email,
        string $number,
        string $nationalId,
        string $name,
        string $subject,
    ): Member {
        if ($email === '' && $number === '' && $nationalId === '' && $name === '') {
            throw new \InvalidArgumentException(__(':subject: provide member_email, member_number, national_id, or member_name.', [
                'subject' => ucfirst($subject),
            ]));
        }

        if ($email !== '') {
            $member = Member::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($member === null) {
                throw new \InvalidArgumentException(__(':subject: no member found for email :email.', [
                    'subject' => ucfirst($subject),
                    'email' => $email,
                ]));
            }

            return $member;
        }

        if ($number !== '') {
            $member = $this->memberResolver->findByMemberNumber($number);

            if ($member === null && $name !== '') {
                $member = $this->memberResolver->findByName($name)
                    ?? $this->memberResolver->findByLegacyHouseholdLabel($name);
            }

            if ($member === null) {
                throw new \InvalidArgumentException(__(':subject: no member found for member_number :number.', [
                    'subject' => ucfirst($subject),
                    'number' => $number,
                ]));
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
                throw new \InvalidArgumentException(__(':subject: no member found for national_id :id.', [
                    'subject' => ucfirst($subject),
                    'id' => $nationalId,
                ]));
            }

            if ($memberIds->count() > 1) {
                throw new \InvalidArgumentException(__(':subject: multiple members found for national_id :id. Use member_number or member_email.', [
                    'subject' => ucfirst($subject),
                    'id' => $nationalId,
                ]));
            }

            $member = Member::query()->find($memberIds->first());
            if ($member === null) {
                throw new \InvalidArgumentException(__(':subject: no member found for national_id :id.', [
                    'subject' => ucfirst($subject),
                    'id' => $nationalId,
                ]));
            }

            return $member;
        }

        $nameMatches = Member::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        if ($nameMatches->count() > 1) {
            throw new \InvalidArgumentException(__(':subject: multiple members found for member_name :name. Use member_number, member_email, or national_id.', [
                'subject' => ucfirst($subject),
                'name' => $name,
            ]));
        }

        if ($nameMatches->count() === 1) {
            return $nameMatches->first();
        }

        $member = $this->memberResolver->findByLegacyHouseholdLabel($name);

        if ($member === null) {
            throw new \InvalidArgumentException(__(':subject: no member found for member_name :name.', [
                'subject' => ucfirst($subject),
                'name' => $name,
            ]));
        }

        return $member;
    }

    private function parseLoanStatus(string $value): string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return 'active';
        }

        if (in_array($normalized, ['pending', 'approved', 'active', 'completed', 'early_settled'], true)) {
            return $normalized;
        }

        throw new \InvalidArgumentException(
            'loan_status must be pending, approved, active, completed, or early_settled (got: '.$value.')'
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseAmountRequestedForPending(array $row): float
    {
        $requested = $this->cell($row, 'amount_requested');
        $approved = $this->cell($row, 'amount_approved');

        if ($requested !== '') {
            return $this->parseMoney($requested, 'amount_requested');
        }

        if ($approved !== '') {
            return $this->parseMoney($approved, 'amount_requested');
        }

        throw new \InvalidArgumentException(__('For pending loans, provide amount_requested or amount_approved.'));
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

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    private function sortRowsByDisbursedAt(array $rows): array
    {
        $withDates = [];

        foreach ($rows as $index => $row) {
            $raw = trim((string) ($row['disbursed_at'] ?? ''));

            try {
                $at = $raw !== '' ? $this->parseDisbursedAt($raw) : BusinessDay::now()->addYears(100);
            } catch (Throwable) {
                $at = BusinessDay::now()->addYears(100);
            }

            $withDates[] = [
                'index' => $index,
                'row' => $row,
                'at' => $at,
            ];
        }

        usort(
            $withDates,
            fn (array $left, array $right): int => $left['at']->timestamp <=> $right['at']->timestamp
            ?: $left['index'] <=> $right['index'],
        );

        return array_map(fn (array $item): array => $item['row'], $withDates);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseAssociativeCsv(string $absolutePath): array
    {
        return AssociativeCsv::read($absolutePath);
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

    private function parseBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true);
    }

    private function parseThreshold(string $value): float
    {
        if ($value === '') {
            if ($this->skipSettlementThresholdForImport === true) {
                return 0.0;
            }

            return LoanSettings::settlementThreshold();
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("settlement_threshold must be numeric (got: {$value})");
        }

        $threshold = (float) $value;
        if ($threshold < 0 || $threshold > 1) {
            throw new \InvalidArgumentException(__('settlement_threshold must be between 0 and 1.'));
        }

        return $threshold;
    }

    private function parseDisbursedAt(string $value): Carbon
    {
        if ($value === '') {
            return BusinessDay::now();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new \InvalidArgumentException("Invalid disbursed_at: {$value}");
        }
    }

    private function parseOptionalDateTime(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new \InvalidArgumentException("Invalid date/time: {$value}");
        }
    }

    private function parseOptionalPositiveInt(string $value, int $default): int
    {
        if ($value === '') {
            return $default;
        }

        if (! ctype_digit($value)) {
            throw new \InvalidArgumentException(__('installments_count must be a positive integer.'));
        }

        $count = (int) $value;

        return $count >= 1 ? $count : throw new \InvalidArgumentException(__('installments_count must be at least 1.'));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveInstallmentsCountForApproved(
        array $row,
        float $amount,
        Member $member,
        float $minInstall,
        float $threshold,
    ): int {
        $cell = $this->cell($row, 'installments_count');
        if ($cell !== '') {
            if (! ctype_digit($cell)) {
                throw new \InvalidArgumentException(__('installments_count must be a positive integer.'));
            }
            $count = (int) $cell;

            return $count >= 1 ? $count : throw new \InvalidArgumentException(__('installments_count must be at least 1.'));
        }

        $fundBal = (float) ($member->fundAccount()?->balance ?? 0);

        return Loan::computeInstallmentsCount($amount, $fundBal, $minInstall, $threshold);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveInstallmentsCountDisbursed(
        array $row,
        float $amount,
        float $memberPortion,
        float $masterPortion,
        float $minInstall,
        float $threshold,
        bool $portionsExplicit,
    ): int {
        if (
            abs($memberPortion - $amount) < 0.02
            && $masterPortion < 0.02
            && $threshold < 0.00001
        ) {
            return 0;
        }

        if (
            $portionsExplicit
            && abs($memberPortion - $amount) < 0.02
            && ($amount - $memberPortion) < 0.02
            && $threshold < 0.00001
        ) {
            return 0;
        }

        $cell = $this->cell($row, 'installments_count');
        if ($cell !== '') {
            if (! ctype_digit($cell)) {
                throw new \InvalidArgumentException(__('installments_count must be a positive integer.'));
            }
            $count = (int) $cell;

            return $count >= 1 ? $count : throw new \InvalidArgumentException(__('installments_count must be at least 1.'));
        }

        if (! $portionsExplicit && $this->fundingStrategyForImport === null) {
            $schedulePortions = LoanSettings::resolveFundingPortions(
                $amount,
                0,
                LoanFundingStrategy::SPLIT_PERCENTAGE,
            );
            $memberPortion = $schedulePortions['member_portion'];
        }

        return Loan::computeInstallmentsCountFromPortions(
            $amount,
            $memberPortion,
            $minInstall,
            $threshold
        );
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parsePaidInstallmentsCount(array $row, int $count): int
    {
        $paidCell = $this->cell($row, 'paid_installments_count');
        if ($paidCell === '') {
            return 0;
        }

        if (! preg_match('/^\d+$/', $paidCell)) {
            throw new \InvalidArgumentException(__('paid_installments_count must be a non-negative integer.'));
        }

        $paidCount = (int) $paidCell;
        if ($paidCount < 0 || $paidCount > $count) {
            throw new \InvalidArgumentException("paid_installments_count must be between 0 and {$count}.");
        }

        return $paidCount;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveLoanTier(array $row, float $amount, bool $isEmergency): ?LoanTier
    {
        $tierNum = $this->cell($row, 'loan_tier_number');
        if ($tierNum !== '') {
            if (! ctype_digit($tierNum)) {
                throw new \InvalidArgumentException(__('loan_tier_number must be a non-negative integer.'));
            }
            $tier = LoanTier::where('tier_number', (int) $tierNum)->where('is_active', true)->first();
            if ($tier === null) {
                throw new \InvalidArgumentException("No active loan tier with tier_number {$tierNum}.");
            }

            return $tier;
        }

        if ($isEmergency) {
            return null;
        }

        $tier = LoanTier::forAmount($amount);
        if ($tier === null) {
            throw new \InvalidArgumentException(__('No active loan tier covers amount_approved; set loan_tier_number.'));
        }

        return $tier;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveLoanTierOptional(array $row, float $amount, bool $isEmergency): ?LoanTier
    {
        $tierNum = $this->cell($row, 'loan_tier_number');
        if ($tierNum !== '') {
            if (! ctype_digit($tierNum)) {
                throw new \InvalidArgumentException(__('loan_tier_number must be a non-negative integer.'));
            }
            $tier = LoanTier::where('tier_number', (int) $tierNum)->where('is_active', true)->first();
            if ($tier === null) {
                throw new \InvalidArgumentException("No active loan tier with tier_number {$tierNum}.");
            }

            return $tier;
        }

        if ($isEmergency) {
            return null;
        }

        return LoanTier::forAmount($amount);
    }

    private function resolveFundTier(array $row, ?LoanTier $loanTier, bool $isEmergency): FundTier
    {
        if ($isEmergency) {
            $emergency = FundTier::emergency();
            if ($emergency === null) {
                throw new \InvalidArgumentException(__('No active emergency fund tier configured.'));
            }

            return $emergency;
        }

        $fundNum = $this->cell($row, 'fund_tier_number');
        if ($fundNum !== '') {
            if (! ctype_digit($fundNum)) {
                throw new \InvalidArgumentException(__('fund_tier_number must be a non-negative integer.'));
            }
            $fundTier = FundTier::where('tier_number', (int) $fundNum)->where('is_active', true)->first();
            if ($fundTier === null) {
                throw new \InvalidArgumentException("No active fund tier with tier_number {$fundNum}.");
            }

            return $fundTier;
        }

        if ($loanTier === null) {
            throw new \InvalidArgumentException(__('Cannot resolve fund tier: set loan_tier_number or is_emergency=1.'));
        }

        $fundTier = FundTier::forLoanTier($loanTier->id);
        if ($fundTier === null) {
            throw new \InvalidArgumentException(__('No active fund tier for this loan tier; set fund_tier_number.'));
        }

        return $fundTier;
    }

    /**
     * @return array{amount: float, interest_rate: float, term_months: int, monthly_repayment: float, total_repaid: float}
     */
    private function baseLoanAttributes(float $amount, int $termMonths): array
    {
        return [
            'amount' => $amount,
            'interest_rate' => LoanSettings::defaultInterestRate(),
            'term_months' => $termMonths,
            'monthly_repayment' => 0,
            'total_repaid' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createLoanRecord(array $attributes, ?int $legacyLoanId = null): Loan
    {
        if ($legacyLoanId === null) {
            return Loan::create($attributes);
        }

        if ($legacyLoanId <= 0) {
            throw new \InvalidArgumentException(__('loan_id must be a positive integer.'));
        }

        if (Loan::withTrashed()->whereKey($legacyLoanId)->exists()) {
            throw new \InvalidArgumentException(__('loan_id :id is already assigned to another loan.', [
                'id' => $legacyLoanId,
            ]));
        }

        $loan = new Loan($attributes);
        $loan->id = $legacyLoanId;
        $loan->save();

        $this->maxLegacyLoanIdUsed = max($this->maxLegacyLoanIdUsed ?? 0, $legacyLoanId);

        return $loan;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function parseOptionalLegacyLoanId(array $row): ?int
    {
        return LegacyLoanCsvIdentity::legacyLoanIdFromRow($row);
    }

    private function syncLoanPrimaryKeySequence(int $usedId): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $next = max($usedId, (int) Loan::withTrashed()->max('id'));
            $connection->statement("SELECT setval(pg_get_serial_sequence('loans', 'id'), ?)", [$next]);

            return;
        }

        if ($driver === 'mysql') {
            $next = max($usedId + 1, (int) Loan::withTrashed()->max('id') + 1);
            $connection->statement('ALTER TABLE loans AUTO_INCREMENT = '.$next);
        }
    }
}
