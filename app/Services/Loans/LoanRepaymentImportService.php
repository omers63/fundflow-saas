<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\MembershipApplication;
use App\Services\LegacyMigration\LegacyImportedLoanScheduleSyncService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class LoanRepaymentImportService
{
    public function __construct(
        private readonly LoanLedgerService $ledger,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
    ) {}

    /**
     * Import loan repayments from a UTF-8 CSV with a header row.
     *
     * @return array{created: int, failed: int, errors: array<int, string>}
     */
    public function import(string $absolutePath): array
    {
        $this->authorizeImport();

        $created = 0;
        $failed = 0;
        $errors = [];
        /** @var list<int> $legacyLoanIds */
        $legacyLoanIds = [];

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
                $this->importRow($row, $legacyLoanIds);
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        if ($legacyLoanIds !== []) {
            $this->scheduleSync->syncLoans($legacyLoanIds);
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
            throw new AuthorizationException(__('You must be signed in to import loan repayments.'));
        }

        if ($user->is_admin) {
            return;
        }

        throw new AuthorizationException(__('You do not have permission to import loan repayments.'));
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $legacyLoanIds
     */
    private function importRow(array $row, array &$legacyLoanIds): void
    {
        $loan = $this->resolveLoan($row);
        $this->assertMemberMatches($row, $loan);

        $installmentNumber = $this->cell($row, 'installment_number');
        $repaymentType = strtolower($this->cell($row, 'repayment_type'));

        if ($installmentNumber !== '' || $repaymentType === 'installment') {
            $this->importInstallmentRow($row, $loan, $installmentNumber);

            return;
        }

        $this->importLegacyRow($row, $loan, $legacyLoanIds);
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<int>  $legacyLoanIds
     */
    private function importLegacyRow(array $row, Loan $loan, array &$legacyLoanIds): void
    {
        if (! in_array($loan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            throw new InvalidArgumentException(__('Loan must be active or settled to receive imported repayments.'));
        }

        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'paid_at')) ?? BusinessDay::now();
        $notes = $this->cell($row, 'notes') ?: __('Imported from CSV');

        DB::transaction(function () use ($loan, $amount, $paidAt, $notes): void {
            $repayment = LoanRepayment::query()->create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $this->ledger->postImportedLoanRepaymentWithCashFlow($loan->fresh(), $repayment, $amount, $paidAt);
        });

        $legacyLoanIds[] = $loan->id;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importInstallmentRow(array $row, Loan $loan, string $installmentNumberRaw): void
    {
        if ($installmentNumberRaw === '') {
            throw new InvalidArgumentException(__('installment_number is required for installment repayments.'));
        }

        if (! is_numeric($installmentNumberRaw)) {
            throw new InvalidArgumentException(__('installment_number must be numeric.'));
        }

        $installmentNumber = (int) $installmentNumberRaw;

        $installment = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('installment_number', $installmentNumber)
            ->first();

        if ($installment === null) {
            throw new InvalidArgumentException(__('Installment :num was not found on this loan.', ['num' => $installmentNumber]));
        }

        if ($installment->isPaid()) {
            throw new InvalidArgumentException(__('Installment :num is already paid.', ['num' => $installmentNumber]));
        }

        $loan->ensureScheduleInstallmentAmount($installment);
        $installment->refresh();

        $amountCell = $this->cell($row, 'amount');
        $expectedAmount = $loan->scheduleInstallmentAmountFor($installmentNumber);
        if ($amountCell !== '' && abs($this->parseMoney($amountCell, 'amount') - $expectedAmount) > 0.02) {
            throw new InvalidArgumentException(__('amount must match the installment amount for historical import rows.'));
        }

        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'paid_at')) ?? BusinessDay::now();
        $lateFee = $this->parseOptionalMoney($this->cell($row, 'late_fee_amount'), 'late_fee_amount') ?? 0.0;

        $this->ledger->ensureMemberAccounts($loan->member);
        $this->ledger->ensureLoanAccount($loan);

        DB::transaction(function () use ($installment, $paidAt, $lateFee): void {
            $installment->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'is_late' => $lateFee > 0.00001,
                'late_fee_amount' => $lateFee > 0.00001 ? $lateFee : 0,
            ]);
        });
    }

    /**
     * @param  array<string, string>  $row
     */
    private function resolveLoan(array $row): Loan
    {
        $loanNumber = $this->cell($row, 'loan_number');

        if ($loanNumber === '') {
            throw new InvalidArgumentException(__('loan_number is required.'));
        }

        if (! is_numeric($loanNumber)) {
            throw new InvalidArgumentException(__('loan_number must be numeric.'));
        }

        $loan = Loan::query()->find((int) $loanNumber);

        if ($loan === null) {
            throw new InvalidArgumentException("No loan found for loan_number: {$loanNumber}");
        }

        return $loan;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function assertMemberMatches(array $row, Loan $loan): void
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $nationalId = $this->cell($row, 'national_id');
        $memberName = $this->cell($row, 'member_name');
        if ($memberName === '') {
            $memberName = $this->cell($row, 'name');
        }

        if ($email === '' && $number === '' && $nationalId === '' && $memberName === '') {
            return;
        }

        $member = $loan->member;

        if ($member === null) {
            throw new InvalidArgumentException(__('Loan has no member.'));
        }

        if ($email !== '' && strtolower((string) $member->email) !== $email) {
            throw new InvalidArgumentException(__('member_email does not match the loan borrower.'));
        }

        if ($number !== '' && (string) $member->member_number !== $number) {
            throw new InvalidArgumentException(__('member_number does not match the loan borrower.'));
        }

        if ($nationalId !== '') {
            $matches = MembershipApplication::query()
                ->where('member_id', $member->id)
                ->where('national_id', $nationalId)
                ->exists();

            if (! $matches) {
                throw new InvalidArgumentException(__('national_id does not match the loan borrower.'));
            }
        }

        if ($memberName !== '' && mb_strtolower((string) $member->name) !== mb_strtolower($memberName)) {
            throw new InvalidArgumentException(__('member_name does not match the loan borrower.'));
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseAssociativeCsv(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
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
            throw new InvalidArgumentException("{$column} is required.");
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$column} must be numeric (got: {$value})");
        }

        return round((float) $value, 2);
    }

    private function parseOptionalMoney(string $value, string $column): ?float
    {
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$column} must be numeric (got: {$value})");
        }

        return round((float) $value, 2);
    }

    private function parseOptionalDateTime(string $value): ?CarbonInterface
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
}
