<?php

namespace App\Models\Tenant;

use App\Support\MemberDateDisplay;
use App\Support\MemberLedgerDescriptionTranslator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'member_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourcedBankTransaction(): ?BankTransaction
    {
        if ($this->reference instanceof BankTransaction) {
            return $this->reference;
        }

        return BankTransaction::query()
            ->where('master_cash_transaction_id', $this->id)
            ->first();
    }

    public function bankImportSummary(): ?string
    {
        $bankTransaction = $this->sourcedBankTransaction();

        if ($bankTransaction === null) {
            return null;
        }

        $date = MemberDateDisplay::format($bankTransaction->transaction_date, 'M j, Y') ?? '—';

        return __('Bank import #:id (:date) — :description', [
            'id' => $bankTransaction->id,
            'date' => $date,
            'description' => MemberLedgerDescriptionTranslator::localize($bankTransaction->description),
        ]);
    }

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }

    /**
     * Signed amount for display: debits are negative, credits positive (matches bank import style).
     */
    public function getSignedAmount(): float
    {
        $amount = abs((float) $this->amount);

        return $this->isDebit() ? -$amount : $amount;
    }

    public function scopeOrderBySignedAmount(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $table = $query->getModel()->getTable();

        return $query->orderByRaw(
            "CASE WHEN {$table}.type = 'debit' THEN -ABS({$table}.amount) ELSE ABS({$table}.amount) END {$direction}",
        );
    }

    public function referenceSummary(): ?string
    {
        if (blank($this->reference_type)) {
            return null;
        }

        $reference = $this->reference;

        if ($reference === null) {
            return class_basename($this->reference_type).' #'.$this->reference_id;
        }

        return match (true) {
            $reference instanceof BankTransaction => $reference->description,
            $reference instanceof FundPosting => filled($reference->reference)
            ? $reference->reference
            : __('Deposit #:id', ['id' => $reference->id]),
            $reference instanceof MembershipApplication => __('Membership application #:id', ['id' => $reference->id]),
            $reference instanceof Contribution => __('Contribution #:id', ['id' => $reference->id]),
            $reference instanceof Loan => __('Loan #:id', ['id' => $reference->id]),
            $reference instanceof LoanRepayment => __('Loan repayment #:id', ['id' => $reference->id]),
            $reference instanceof InvestDisbursement => __('Investment #:id', ['id' => $reference->id]),
            $reference instanceof InvestReturn => __('Investment return #:id', ['id' => $reference->id]),
            $reference instanceof ExpenseDisbursement => __('Expense #:id', ['id' => $reference->id]),
            $reference instanceof self => __('Transaction #:id', ['id' => $reference->id]),
            default => class_basename($reference).': #'.$reference->getKey(),
        };
    }

    public function ledgerAccountLabel(bool $memberFacing = false): string
    {
        $this->loadMissing('account');

        $account = $this->account;

        if ($account === null) {
            return __('—');
        }

        return $memberFacing ? $account->memberFacingLabel() : $account->displayLabel();
    }

    /**
     * Structured reference rows for transaction detail modals.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function modalReferenceItems(bool $memberFacing = false): array
    {
        $items = [];

        if ($this->reference_type === InvestDisbursement::class && $this->reference_id !== null) {
            $items[] = [
                'label' => __('Investment #'),
                'value' => (string) $this->reference_id,
            ];
        }

        if ($this->reference_type === InvestReturn::class && $this->reference_id !== null) {
            $items[] = [
                'label' => __('Investment return #'),
                'value' => (string) $this->reference_id,
            ];
        }

        if ($this->reference_type === ExpenseDisbursement::class && $this->reference_id !== null) {
            $items[] = [
                'label' => __('Expense #'),
                'value' => (string) $this->reference_id,
            ];
        }

        if ($this->reference_type === self::class && $this->reference_id !== null) {
            $referenced = self::query()->with('account')->find($this->reference_id);

            if ($referenced?->account !== null) {
                $items[] = [
                    'label' => __('Referenced account'),
                    'value' => __(':account — transaction #:id', [
                        'account' => $referenced->ledgerAccountLabel($memberFacing),
                        'id' => $referenced->id,
                    ]),
                ];
            }
        }

        $linkedSource = $this->referenceSummary();
        $bankImport = $this->bankImportSummary();

        if (filled($linkedSource) && $linkedSource !== $bankImport) {
            $items[] = [
                'label' => __('Linked source'),
                'value' => $linkedSource,
            ];
        }

        if (filled($bankImport)) {
            $items[] = [
                'label' => __('Bank import'),
                'value' => $bankImport,
            ];
        }

        return $items;
    }

    public function memberFacingDescription(): string
    {
        if ($this->isLateFeeTransaction()) {
            return $this->lateFeeDescription();
        }

        $reference = $this->reference;

        if ($reference !== null) {
            return match (true) {
                $reference instanceof Contribution => __('Contribution — :period', [
                    'period' => $reference->period?->locale(app()->getLocale())->translatedFormat('M Y') ?? '—',
                ]),
                $reference instanceof FundPosting => filled($reference->reference)
                ? (string) $reference->reference
                : __('Deposit'),
                $reference instanceof LoanInstallment => __('EMI — loan #:id cycle :n', [
                    'id' => $reference->loan_id,
                    'n' => $reference->installment_number,
                ]),
                $reference instanceof LoanRepayment => __('Loan repayment — loan #:id', [
                    'id' => $reference->loan_id,
                ]),
                $reference instanceof Loan => __('Loan disbursement — loan #:id', [
                    'id' => $reference->id,
                ]),
                $reference instanceof CashOutRequest => __('Cash out'),
                $reference instanceof FeeDeduction => MemberLedgerDescriptionTranslator::localize(
                    filled($reference->description) ? (string) $reference->description : __('Fee deduction'),
                ),
                $reference instanceof BankTransaction => MemberLedgerDescriptionTranslator::localize(
                    filled($reference->description) ? (string) $reference->description : __('Bank transaction'),
                ),
                default => MemberLedgerDescriptionTranslator::localize(
                    $this->referenceSummary() ?? $this->fallbackDescription(),
                ),
            };
        }

        return $this->fallbackDescription();
    }

    public function displayDescription(): string
    {
        if (filled($this->description)) {
            return MemberLedgerDescriptionTranslator::localize((string) $this->description);
        }

        $summary = $this->referenceSummary();

        if ($summary !== null) {
            return MemberLedgerDescriptionTranslator::localize($summary);
        }

        return __('Transaction #:id', ['id' => $this->id]);
    }

    public function hasLinkedReference(): bool
    {
        return filled($this->reference_type) && filled($this->reference_id);
    }

    public function linkedSourceLabel(): string
    {
        if (! $this->hasLinkedReference()) {
            return __('No linked source');
        }

        return $this->referenceSummary()
            ?? class_basename((string) $this->reference_type).' #'.$this->reference_id;
    }

    public function linkedSourceDetail(): string
    {
        if (! $this->hasLinkedReference()) {
            return __('Manual entry or missing reference — not tied to a contribution, loan, deposit, or other source record.');
        }

        return $this->linkedSourceLabel();
    }

    public function memberActivityCategoryLabel(): string
    {
        if ($this->isLateFeeTransaction()) {
            return __('Late fee');
        }

        $description = (string) ($this->description ?? '');

        if (preg_match('/^(Transfer to|Transfer from|Allocation —)/', $description) === 1) {
            return __('Allocation');
        }

        return match ($this->reference_type) {
            Contribution::class => __('Contribution'),
            FundPosting::class => __('Deposit'),
            LoanInstallment::class, LoanRepayment::class => __('EMI'),
            Loan::class => __('Loan'),
            CashOutRequest::class => __('Cash out'),
            FeeDeduction::class => __('Late fee'),
            default => self::typeLabel($this->type),
        };
    }

    public function memberFacingTypeLabel(): string
    {
        return self::typeLabel($this->type);
    }

    public function memberExportReference(): string
    {
        $reference = __('Txn #:id', ['id' => $this->id]);

        if (blank($this->reference_type) || blank($this->reference_id)) {
            return $reference;
        }

        return $reference.' · '.class_basename($this->reference_type).' #'.$this->reference_id;
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'credit' => __('Credit'),
            'debit' => __('Debit'),
            default => filled($type) ? ucfirst((string) $type) : __('—'),
        };
    }

    private function isLateFeeTransaction(): bool
    {
        if ($this->reference_type === FeeDeduction::class) {
            return true;
        }

        $description = (string) ($this->description ?? '');

        return preg_match('/^(Contribution late fee —|EMI late fee —)/', $description) === 1;
    }

    private function lateFeeDescription(): string
    {
        $description = (string) ($this->description ?? '');

        if (preg_match('/^Contribution late fee — (.+)$/', $description, $matches) === 1) {
            return __('Contribution late fee — :period', [
                'period' => MemberLedgerDescriptionTranslator::localizePeriod($matches[1]),
            ]);
        }

        if (preg_match('/^EMI late fee — (.+)$/', $description, $matches) === 1) {
            return MemberLedgerDescriptionTranslator::localize($description);
        }

        $reference = $this->reference;

        if ($reference instanceof FeeDeduction && filled($reference->description)) {
            return MemberLedgerDescriptionTranslator::localize((string) $reference->description);
        }

        return __('Late fee');
    }

    private function fallbackDescription(): string
    {
        if (filled($this->description)) {
            return MemberLedgerDescriptionTranslator::localize((string) $this->description);
        }

        $summary = $this->referenceSummary();

        return $summary !== null
            ? MemberLedgerDescriptionTranslator::localize($summary)
            : '—';
    }
}
