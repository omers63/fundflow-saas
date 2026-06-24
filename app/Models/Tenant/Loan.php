<?php

namespace App\Models\Tenant;

use App\Services\Loans\LoanEarlySettlementService;
use App\Support\BusinessDay;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'member_id',
        'loan_tier_id',
        'fund_tier_id',
        'queue_position',
        'amount',
        'amount_requested',
        'amount_approved',
        'amount_disbursed',
        'interest_rate',
        'term_months',
        'monthly_repayment',
        'total_repaid',
        'completed_at',
        'member_portion',
        'master_portion',
        'repaid_to_master',
        'purpose',
        'installments_count',
        'status',
        'applied_at',
        'approved_at',
        'approved_by_id',
        'disbursed_at',
        'has_grace_cycle',
        'grace_cycles',
        'original_borrower_member_id',
        'transferred_to_guarantor_at',
        'settled_at',
        'due_date',
        'guarantor_member_id',
        'guarantor_released_at',
        'guarantor_liability_transferred_at',
        'witness1_name',
        'witness1_phone',
        'witness2_name',
        'witness2_phone',
        'exempted_month',
        'exempted_year',
        'first_repayment_month',
        'first_repayment_year',
        'settlement_threshold',
        'late_repayment_count',
        'late_repayment_amount',
        'rejection_reason',
        'cancellation_reason',
        'is_emergency',
        'funding_strategy',
        'cash_out_excess_fund',
        'payout_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'amount_disbursed' => 'decimal:2',
            'member_portion' => 'decimal:2',
            'master_portion' => 'decimal:2',
            'repaid_to_master' => 'decimal:2',
            'late_repayment_amount' => 'decimal:2',
            'settlement_threshold' => 'decimal:4',
            'is_emergency' => 'boolean',
            'cash_out_excess_fund' => 'boolean',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'has_grace_cycle' => 'boolean',
            'settled_at' => 'datetime',
            'guarantor_released_at' => 'datetime',
            'guarantor_liability_transferred_at' => 'datetime',
            'transferred_to_guarantor_at' => 'datetime',
            'grace_cycles' => 'integer',
            'due_date' => 'date',
            'payout_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------------

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loanTier(): BelongsTo
    {
        return $this->belongsTo(LoanTier::class);
    }

    public function fundTier(): BelongsTo
    {
        return $this->belongsTo(FundTier::class);
    }

    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'guarantor_member_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class);
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class);
    }

    public function account(): ?Account
    {
        return Account::query()->where('loan_id', $this->id)->where('type', Account::TYPE_LOAN)->first();
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    // -----------------------------------------------------------------------
    // Status helpers
    // -----------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'partially_disbursed'], true);
    }

    public function isPartiallyDisbursed(): bool
    {
        return $this->status === 'partially_disbursed';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'early_settled']);
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'rejected']);
    }

    /** True when repayments are still due on this active loan (contributions are blocked for the member). */
    public function isExemptingContributions(): bool
    {
        return $this->isActive()
            && $this->installments()->whereIn('status', ['pending', 'overdue'])->exists();
    }

    /**
     * True when the total amount disbursed equals (or exceeds) the approved amount.
     * Existing single-shot loans have amount_disbursed backfilled = amount_approved.
     */
    public function isFullyDisbursed(): bool
    {
        return (float) $this->amount_disbursed >= (float) $this->amount_approved - 0.001;
    }

    /**
     * The outstanding amount still to be disbursed (approved − disbursed so far).
     */
    public function remainingToDisburse(): float
    {
        return max(0.0, (float) $this->amount_approved - (float) $this->amount_disbursed);
    }

    // -----------------------------------------------------------------------
    // Guarantor helpers
    // -----------------------------------------------------------------------

    public function isGuarantorReleased(): bool
    {
        return $this->guarantor_released_at !== null;
    }

    /**
     * Release the guarantor when master_portion is fully repaid.
     * Called by LoanDefaultService / LoanRepaymentService after each repayment.
     */
    public function releaseGuarantorIfDue(): void
    {
        if (
            ! $this->isGuarantorReleased() && $this->guarantor_member_id &&
            (float) $this->repaid_to_master >= (float) $this->master_portion
        ) {
            $this->update(['guarantor_released_at' => BusinessDay::now()]);
        }
    }

    // -----------------------------------------------------------------------
    // Settlement helpers
    // -----------------------------------------------------------------------

    /**
     * True when both settlement conditions are met:
     *  1. Master portion fully repaid.
     *  2. Member's fund account balance ≥ settlement_threshold × amount_approved.
     */
    public function isReadyToSettle(): bool
    {
        if ((float) $this->repaid_to_master < (float) $this->master_portion) {
            return false;
        }

        $fundBalance = (float) ($this->member->fundAccount?->balance ?? 0);
        $required = (float) $this->amount_approved * (float) $this->settlement_threshold;

        return $fundBalance >= $required;
    }

    /**
     * Cumulative repayment target (master slice + settlement portion) per schedule build.
     */
    public function fullRepaymentThreshold(): float
    {
        return round(
            (float) $this->master_portion + ((float) $this->amount_approved * (float) $this->settlement_threshold),
            2,
        );
    }

    /**
     * Sum of principal collected on paid installments (cash debits / amount_collected).
     */
    public function totalPrincipalCollected(): float
    {
        return (float) $this->installments()
            ->where('status', 'paid')
            ->get()
            ->sum(fn (LoanInstallment $installment): float => (float) ($installment->amount_collected > 0
                ? $installment->amount_collected
                : $installment->amount));
    }

    /**
     * Representative EMI amount for over-collection comparisons (first unpaid or last paid).
     */
    public function representativeEmiAmount(): float
    {
        $pending = $this->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('installment_number')
            ->value('amount');

        if ($pending !== null) {
            return (float) $pending;
        }

        $paid = $this->installments()
            ->where('status', 'paid')
            ->orderByDesc('installment_number')
            ->value('amount');

        return (float) ($paid ?? $this->monthly_repayment ?? 0);
    }

    // -----------------------------------------------------------------------
    // Installment helpers
    // -----------------------------------------------------------------------

    public function getPaidInstallmentsCountAttribute(): int
    {
        return $this->installments()->where('status', 'paid')->count();
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) $this->installments()->whereIn('status', ['pending', 'overdue'])->sum('amount');
    }

    /**
     * Cash required to pay off all remaining installments now (principal + late fees per cycle rules).
     */
    public function remainingSettlementCashRequired(): float
    {
        return app(LoanEarlySettlementService::class)->requiredCash($this);
    }

    public function hasOverdueInstallments(): bool
    {
        return $this->installments()->where('status', 'overdue')->exists();
    }

    /**
     * Mark active loan completed when all installments are paid.
     */
    public function syncPaidOffStatusFromInstallments(): void
    {
        if ($this->status !== 'active') {
            return;
        }

        $hasInstallments = $this->installments()->exists();
        if (! $hasInstallments) {
            return;
        }

        $hasUnpaid = $this->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->exists();

        if ($hasUnpaid) {
            return;
        }

        $settledAt = $this->installments()
            ->whereNotNull('paid_at')
            ->max('paid_at');

        $this->update([
            'status' => 'completed',
            'settled_at' => $settledAt ?? BusinessDay::now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Repayment cycle: contribution exemption logic
    // -----------------------------------------------------------------------

    /**
     * Compute the number of monthly installments needed to fully repay the loan.
     *
     * Formula:
     *   installments = ceil( (master_portion + settlement_threshold × loan_amount)
     *                        / min_monthly_installment )
     *
     * Where member/master portions follow {@see LoanSettings::resolveFundingPortions()}.
     */
    public static function computeInstallmentsCount(
        float $loanAmount,
        float $memberFundBalance,
        float $minMonthlyInstallment,
        float $settlementThresholdPct,
        ?string $fundingStrategy = null,
    ): int {
        $portions = LoanSettings::resolveFundingPortions($loanAmount, $memberFundBalance, $fundingStrategy);

        return self::computeInstallmentsCountFromPortions(
            $loanAmount,
            $portions['member_portion'],
            $minMonthlyInstallment,
            $settlementThresholdPct,
        );
    }

    /**
     * Same repayment horizon as {@see computeInstallmentsCount} but using known portions (e.g. CSV import).
     */
    public static function computeInstallmentsCountFromPortions(
        float $loanAmount,
        float $memberPortion,
        float $minMonthlyInstallment,
        float $settlementThresholdPct,
    ): int {
        $masterPortion = $loanAmount - $memberPortion;
        $settlementAmt = $loanAmount * $settlementThresholdPct;
        $totalToRepay = $masterPortion + $settlementAmt;

        return max(1, (int) ceil($totalToRepay / max(1, $minMonthlyInstallment)));
    }

    /**
     * Determine which contribution cycle is exempted and when repayments start
     * based on the disbursement date.
     *
     * Cutoff day aligns with the contribution cycle: the due date for a cycle is the day before
     * the next cycle starts (see Setting::contributionCycleStartDay). If disbursed on or before
     * that day number in the month (e.g. day 5 when cycle starts on the 6th), exempt the previous
     * calendar month's contribution; otherwise exempt the current month.
     *
     * With grace, first_repayment_* is the **calendar month of the installment due date** (e.g. 5th —
     * the day after cutoff). Grace is stored as exempted_month/year (calendar month labeling the
     * skipped cycle). Apply {@see finalizeExemptionForDisbursement()} after this so an existing
     * contribution for the grace period shifts grace and first repayment together.
     */
    /**
     * @param  bool|int  $grace  Legacy bool (true = 1 cycle) or explicit grace_cycles 0–2
     * @return array{exempted_month: int|null, exempted_year: int|null, first_repayment_month: int, first_repayment_year: int}
     */
    public static function computeExemptionAndFirstRepayment(Carbon $disbursedAt, bool|int $grace = true): array
    {
        $graceCycles = is_int($grace)
            ? max(0, min(2, $grace))
            : ($grace ? 1 : 0);
        $hasGraceCycle = $graceCycles > 0;

        $cutoffDay = max(1, Setting::contributionCycleStartDay() - 1);

        if ($disbursedAt->day <= $cutoffDay) {
            $first = $disbursedAt->copy();
        } else {
            $first = $disbursedAt->copy()->addMonthNoOverflow();
        }

        $exempted = null;
        if ($hasGraceCycle) {
            $exempted = $disbursedAt->day <= $cutoffDay
                ? $disbursedAt->copy()->subMonthNoOverflow()
                : $disbursedAt->copy();
            $first = $first->copy()->addMonthNoOverflow();
        }

        $result = [
            'exempted_month' => $exempted ? (int) $exempted->month : null,
            'exempted_year' => $exempted ? (int) $exempted->year : null,
            'first_repayment_month' => (int) $first->month,
            'first_repayment_year' => (int) $first->year,
        ];

        for ($i = 1; $i < $graceCycles; $i++) {
            $result = static::shiftGraceAndFirstRepaymentOneMonth($result);
        }

        return $result;
    }

    /**
     * True if the member has a non-deleted contribution for the given calendar month/year
     * recorded on or before {@code $asOf} (uses paid_at when set, otherwise created_at).
     */
    public static function memberHasContributionForCycleAsOf(int $memberId, int $month, int $year, Carbon $asOf): bool
    {
        $period = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();

        return Contribution::query()
            ->where('member_id', $memberId)
            ->where('period', $period)
            ->where('created_at', '<=', $asOf)
            ->exists();
    }

    /**
     * After {@see computeExemptionAndFirstRepayment()}, align grace and first repayment with
     * contributions already on file as of disbursement:
     *
     * 1. Grace (exempted month/year): if the member already contributed for that period on or
     *    before disbursement, shift grace forward one calendar month and shift first repayment by
     *    the same amount (repeat). For example, disburse 21 Sept — no Sept contribution: grace
     *    Sept, first due 5 Nov. Sept contribution present before disbursement: grace Oct,
     *    first due 5 Dec (installment day 5 uses the stored first_repayment calendar month).
     *
     * 2. First repayment month: if a contribution already exists for that calendar period as of
     *    disbursement, advance only first repayment until an unoccupied month is found.
     */
    public static function finalizeExemptionForDisbursement(Member $member, array $exemption, Carbon $disbursedAt): array
    {
        $e = $exemption;

        for ($i = 0; $i < 24; $i++) {
            if ($e['exempted_month'] === null || $e['exempted_year'] === null) {
                break;
            }
            if (
                ! static::memberHasContributionForCycleAsOf(
                    (int) $member->id,
                    (int) $e['exempted_month'],
                    (int) $e['exempted_year'],
                    $disbursedAt,
                )
            ) {
                break;
            }
            $e = static::shiftGraceAndFirstRepaymentOneMonth($e);
        }

        $m = (int) $e['first_repayment_month'];
        $y = (int) $e['first_repayment_year'];

        for ($i = 0; $i < 24; $i++) {
            if (! static::memberHasContributionForCycleAsOf((int) $member->id, $m, $y, $disbursedAt)) {
                break;
            }
            $next = Carbon::create($y, $m, 1)->addMonthNoOverflow();
            $m = (int) $next->month;
            $y = (int) $next->year;
        }

        return [
            ...$e,
            'first_repayment_month' => $m,
            'first_repayment_year' => $y,
        ];
    }

    /**
     * Advance grace (exempted) and first repayment together by one calendar month.
     *
     * @param  array{exempted_month: int|null, exempted_year: int|null, first_repayment_month: int, first_repayment_year: int}  $exemption
     * @return array{exempted_month: int|null, exempted_year: int|null, first_repayment_month: int, first_repayment_year: int}
     */
    protected static function shiftGraceAndFirstRepaymentOneMonth(array $exemption): array
    {
        $exNext = Carbon::create((int) $exemption['exempted_year'], (int) $exemption['exempted_month'], 1)->addMonthNoOverflow();
        $firstNext = Carbon::create((int) $exemption['first_repayment_year'], (int) $exemption['first_repayment_month'], 1)->addMonthNoOverflow();

        return [
            ...$exemption,
            'exempted_month' => (int) $exNext->month,
            'exempted_year' => (int) $exNext->year,
            'first_repayment_month' => (int) $firstNext->month,
            'first_repayment_year' => (int) $firstNext->year,
        ];
    }

    // -----------------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['pending', 'approved', 'partially_disbursed', 'active', 'transferred']);
    }

    public function scopeInRepayment($query)
    {
        return $query->whereIn('status', ['active', 'transferred']);
    }

    /** Pending review or approved but not yet fully disbursed. */
    public function scopeInQueue($query)
    {
        return $query->where(function ($q): void {
            $q->where('status', 'pending')
                ->orWhereIn('status', ['approved', 'partially_disbursed']);
        });
    }

    public function scopeNeedsDecision($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeReadyToDisburse($query)
    {
        return $query->whereIn('status', ['approved', 'partially_disbursed'])
            ->whereRaw('COALESCE(amount_disbursed, 0) < COALESCE(amount_approved, amount_requested, 0)');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'pending' => __('Pending admin review'),
            'approved' => __('Approved'),
            'partially_disbursed' => __('Partially disbursed'),
            'active' => __('Active'),
            'transferred' => __('Transferred to guarantor'),
            'completed' => __('Repaid'),
            'early_settled' => __('Repaid early'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'approved', 'partially_disbursed' => 'info',
            'active' => 'success',
            'transferred' => 'warning',
            'completed', 'early_settled' => 'gray',
            'rejected', 'cancelled' => 'danger',
            default => 'gray',
        };
    }

    public function getOutstandingBalance(): float
    {
        if ($this->relationLoaded('installments')) {
            return (float) $this->installments
                ->whereIn('status', ['pending', 'overdue'])
                ->sum('amount');
        }

        return (float) $this->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('amount');
    }

    public function scopeOrderByOutstanding(Builder $query, string $direction = 'desc'): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(
            LoanInstallment::query()
                ->selectRaw('coalesce(sum(amount), 0)')
                ->whereColumn('loan_installments.loan_id', 'loans.id')
                ->whereIn('status', ['pending', 'overdue']),
            $direction,
        );
    }
}
