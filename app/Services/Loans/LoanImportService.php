<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\MemberCashOutService;
use App\Support\LegacyMemberIdentifierResolver;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class LoanImportService
{
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
        $appliedAt = $this->parseOptionalDateTime($this->cell($row, 'applied_at')) ?? now();

        Loan::create([
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
        ]);
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
        $approvedAt = $this->parseOptionalDateTime($this->cell($row, 'approved_at')) ?? now();
        $appliedAt = $this->parseOptionalDateTime($this->cell($row, 'applied_at')) ?? $approvedAt;

        Loan::create([
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
        ]);

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

        [$memberPortion, $masterPortion] = $this->resolvePortions($row, $amount, $member);

        $isEmergency = $this->parseBool($this->cell($row, 'is_emergency'));
        $loanTier = $this->resolveLoanTier($row, $amount, $isEmergency);
        $fundTier = $this->resolveFundTier($row, $loanTier, $isEmergency);
        $threshold = $this->parseThreshold($this->cell($row, 'settlement_threshold'));
        $minInstall = (float) ($loanTier?->min_monthly_installment ?? 1000);
        $count = $this->resolveInstallmentsCountDisbursed($row, $amount, $memberPortion, $minInstall, $threshold);
        $paidCount = $allPaid ? $count : $this->parsePaidInstallmentsCount($row, $count);
        $disbursedAt = $this->parseDisbursedAt($this->cell($row, 'disbursed_at'));
        $exemption = Loan::computeExemptionAndFirstRepayment($disbursedAt);
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

        DB::transaction(function () use ($member, $loanTier, $fundTier, $amount, $amountRequested, $purpose, $count, $disbursedAt, $exemption, $threshold, $isEmergency, $memberPortion, $masterPortion, $paidCount, $minInstall, $totalRepaid, $terminalStatus, $settledAt, $appliedAt, $approvedAt, $guarantorMemberId): void {
            $loan = Loan::create([
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
                'settlement_threshold' => $threshold,
                'is_emergency' => $isEmergency,
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'guarantor_member_id' => $guarantorMemberId,
            ]);

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

            $startDate = Carbon::create(
                $exemption['first_repayment_year'],
                $exemption['first_repayment_month'],
                5
            );

            for ($i = 1; $i <= $count; $i++) {
                $dueDate = $startDate->copy()->addMonths($i - 1);
                $isPaid = $i <= $paidCount;
                LoanInstallment::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $i,
                    'amount' => $minInstall,
                    'due_date' => $dueDate->toDateString(),
                    'paid_at' => $isPaid ? $dueDate->copy()->startOfDay() : null,
                    'status' => $isPaid ? 'paid' : 'pending',
                ]);
            }

            if ($terminalStatus !== 'active') {
                $loan->update([
                    'status' => $terminalStatus,
                    'settled_at' => $settledAt,
                ]);
            }
        });

        LoanQueueOrderingService::resequenceFundTier($fundTier->id);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: float, 1: float}
     */
    private function resolvePortions(array $row, float $amount, Member $member): array
    {
        $memberCell = $this->cell($row, 'member_portion');
        $masterCell = $this->cell($row, 'master_portion');

        if ($memberCell === '' && $masterCell === '') {
            // Legacy migration defaults to the baseline loan flow:
            // member fund takes the full disbursement and can go negative.
            $memberPortion = round($amount, 2);
            $masterPortion = 0.0;
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

        return [$memberPortion, $masterPortion];
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

    private function parseBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true);
    }

    private function parseThreshold(string $value): float
    {
        if ($value === '') {
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
            return now();
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
}
