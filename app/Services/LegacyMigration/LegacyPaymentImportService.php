<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
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
    public function __construct(
        private readonly ContributionService $contributions,
        private readonly LoanLedgerService $ledger,
    ) {
    }

    /**
     * Import classified legacy payments (contribution / loan_repayment / ignore).
     *
     * @return array{contributions: int, loan_repayments: int, ignored: int, failed: int, errors: list<string>}
     */
    public function import(string $absolutePath): array
    {
        $rows = AssociativeCsv::read($absolutePath);

        if ($rows === []) {
            return [
                'contributions' => 0,
                'loan_repayments' => 0,
                'ignored' => 0,
                'failed' => 0,
                'errors' => [__('The file is empty or has no data rows after the header.')],
            ];
        }

        $contributions = 0;
        $loanRepayments = 0;
        $ignored = 0;
        $failed = 0;
        $errors = [];
        $lineBase = 2;

        foreach ($rows as $index => $row) {
            $lineNumber = $lineBase + $index;

            try {
                $type = strtolower($this->cell($row, 'payment_type'));

                match ($type) {
                    'ignore', 'skipped', 'skip' => $ignored++,
                    'contribution' => $this->importContributionRow($row) ? $contributions++ : $ignored++,
                    'loan_repayment', 'loan', 'repayment' => $this->importLoanRepaymentRow($row) ? $loanRepayments++ : $ignored++,
                    'unclassified' => throw new InvalidArgumentException(__('Row is still unclassified — set payment_type to contribution or loan_repayment.')),
                    default => throw new InvalidArgumentException(__('Unknown payment_type: :type', ['type' => $type])),
                };
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
            }
        }

        return [
            'contributions' => $contributions,
            'loan_repayments' => $loanRepayments,
            'ignored' => $ignored,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importContributionRow(array $row): bool
    {
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $periodRaw = $this->cell($row, 'period');
        $paymentDate = $this->cell($row, 'payment_date');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');

        $member = $email !== ''
            ? Member::query()->whereRaw('LOWER(email) = ?', [$email])->first()
            : Member::query()->where('member_number', $number)->first();

        if ($member === null) {
            throw new InvalidArgumentException(__('Member not found for contribution row.'));
        }

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
        $month = (int) $date->month;
        $year = (int) $date->year;

        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        if (Contribution::memberPeriodRecordExists((int) $member->id, $month, $year)) {
            return false;
        }

        $postedAt = $paymentDate !== '' ? Carbon::parse($paymentDate) : BusinessDay::now();
        $notes = $this->cell($row, 'notes') ?: __('Legacy migration contribution');

        DB::transaction(function () use ($member, $month, $year, $amount, $postedAt, $notes): void {
            $contribution = Contribution::query()->create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $amount,
                'amount_due' => $amount,
                'amount_collected' => 0,
                'status' => 'pending',
                'collection_status' => ContributionCollectionStatus::PENDING,
                'payment_method' => Contribution::PAYMENT_METHOD_IMPORT_CSV,
                'notes' => $notes,
            ]);

            $this->contributions->postContribution($contribution->fresh());

            $contribution->refresh()->update([
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'amount_collected' => $amount,
                'posted_at' => $postedAt,
                'paid_at' => $postedAt,
            ]);
        });

        return true;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importLoanRepaymentRow(array $row): bool
    {
        $loanNumber = $this->cell($row, 'loan_number') ?: $this->cell($row, 'suggested_loan_number');
        $email = strtolower($this->cell($row, 'member_email'));
        $number = $this->cell($row, 'member_number');
        $amount = $this->parseMoney($this->cell($row, 'amount'), 'amount');
        $paidAt = $this->parseOptionalDateTime($this->cell($row, 'payment_date')) ?? BusinessDay::now();
        $notes = $this->cell($row, 'notes') ?: __('Legacy migration loan repayment');

        $loan = null;

        if ($loanNumber !== '' && is_numeric($loanNumber)) {
            $loan = Loan::query()->find((int) $loanNumber);
        }

        if ($loan === null) {
            $member = $email !== ''
                ? Member::query()->whereRaw('LOWER(email) = ?', [$email])->first()
                : Member::query()->where('member_number', $number)->first();

            if ($member === null) {
                throw new InvalidArgumentException(__('Member not found for loan repayment row.'));
            }

            $loan = $member->loans()
                ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled'])
                ->orderByDesc('disbursed_at')
                ->first();
        }

        if ($loan === null) {
            throw new InvalidArgumentException(__('No loan found for repayment row.'));
        }

        if (!in_array($loan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            throw new InvalidArgumentException(__('Loan must be active or settled to receive imported repayments.'));
        }

        DB::transaction(function () use ($loan, $amount, $paidAt, $notes): void {
            LoanRepayment::query()->create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'notes' => $notes,
            ]);

            $this->ledger->postImportedLoanRepayments($loan->fresh(), $amount);
        });

        return true;
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
        if ($value === '' || !is_numeric($value)) {
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
}
