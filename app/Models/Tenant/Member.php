<?php

namespace App\Models\Tenant;

use App\Services\LoanService;
use App\Services\MemberMonthlyAllocationService;
use App\Support\MemberNumberSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Member extends Model
{
    use HasFactory;

    /** @var list<int> */
    public const CONTRIBUTION_STEPS = [500, 1000, 1500, 2000, 2500, 3000];

    /** @var list<string> */
    public const STATUSES = ['active', 'suspended', 'withdrawn', 'delinquent', 'terminated'];

    /** @var list<string> */
    public const PORTAL_BLOCKED_STATUSES = ['suspended', 'withdrawn', 'delinquent', 'terminated'];

    protected $fillable = [
        'user_id',
        'parent_member_id',
        'member_number',
        'name',
        'email',
        'phone',
        'household_email',
        'is_separated',
        'direct_login_enabled',
        'portal_pin',
        'monthly_contribution_amount',
        'joined_at',
        'contribution_arrears_cutoff_date',
        'migration_cutoff_date',
        'migration_status',
        'partial_clearance_granted_at',
        'partial_clearance_notes',
        'opening_cash_balance',
        'opening_fund_balance',
        'opening_balances_posted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_contribution_amount' => 'decimal:2',
            'joined_at' => 'date',
            'contribution_arrears_cutoff_date' => 'date',
            'migration_cutoff_date' => 'date',
            'partial_clearance_granted_at' => 'datetime',
            'opening_cash_balance' => 'decimal:2',
            'opening_fund_balance' => 'decimal:2',
            'opening_balances_posted_at' => 'datetime',
            'is_separated' => 'boolean',
            'direct_login_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Member $member): void {
            if (! $member->isDirty('monthly_contribution_amount')) {
                return;
            }

            app(MemberMonthlyAllocationService::class)->assertCanChangeMonthlyContribution($member);
        });
    }

    public function isParent(): bool
    {
        return $this->parent_member_id === null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_member_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_member_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function cashAccount(): HasOne
    {
        return $this->hasOne(Account::class)->where('type', 'cash')->where('is_master', false);
    }

    public function fundAccount(): HasOne
    {
        return $this->hasOne(Account::class)->where('type', 'fund')->where('is_master', false);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function fundPostings(): HasMany
    {
        return $this->hasMany(FundPosting::class);
    }

    public function migrationInstalmentSchedules(): HasMany
    {
        return $this->hasMany(MigrationInstalmentSchedule::class);
    }

    public function repayments(): HasManyThrough
    {
        return $this->hasManyThrough(LoanRepayment::class, Loan::class);
    }

    /**
     * Paid installments across all member loans (member repayments tab).
     */
    public function paidLoanInstallments(): HasManyThrough
    {
        return $this->hasManyThrough(LoanInstallment::class, Loan::class)
            ->where('loan_installments.status', 'paid');
    }

    /**
     * Admin/member direct messages for this member's login user (filtered further in the relation manager).
     */
    public function directMessages(): HasMany
    {
        return $this->hasMany(DirectMessage::class, 'to_user_id', 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function loanEligibilityStartDate(): ?Carbon
    {
        return $this->joined_at;
    }

    /**
     * First calendar month for which contribution arrears may apply (cut-off import or join date).
     */
    public function contributionLiabilityStartMonth(): ?Carbon
    {
        $date = $this->contribution_arrears_cutoff_date ?? $this->joined_at;

        return $date?->copy()->startOfMonth();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWithParent($query)
    {
        return $query->whereNotNull('parent_member_id');
    }

    public function scopeIndependent($query)
    {
        return $query->whereNull('parent_member_id');
    }

    public static function generateMemberNumber(): string
    {
        return MemberNumberSettings::generate();
    }

    public static function isValidContributionAmount(int $amount): bool
    {
        return in_array($amount, self::CONTRIBUTION_STEPS, true);
    }

    /**
     * @return array<int, string>
     */
    public static function contributionAmountOptions(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $options = [];

        foreach (self::CONTRIBUTION_STEPS as $amount) {
            $options[$amount] = number_format($amount, 0).' '.$currency;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'active' => __('Active'),
            'suspended' => __('Suspended'),
            'withdrawn' => __('Withdrawn'),
            'delinquent' => __('Delinquent'),
            'terminated' => __('Terminated'),
        ];
    }

    public static function statusBadgeColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'suspended' => 'warning',
            'withdrawn', 'delinquent', 'terminated' => 'danger',
            default => 'gray',
        };
    }

    public static function portalBlockedSessionKey(string $status): string
    {
        return match ($status) {
            'withdrawn' => 'member_withdrawn_notice',
            'delinquent' => 'member_delinquent_notice',
            'terminated' => 'member_terminated_notice',
            default => 'member_suspended_notice',
        };
    }

    public function isEligibleForLoan(): bool
    {
        return app(LoanService::class)->checkEligibility($this)['eligible'];
    }

    public function getFundBalance(): float
    {
        return (float) ($this->fundAccount?->balance ?? 0);
    }

    public function getCashBalance(): float
    {
        return (float) ($this->cashAccount?->balance ?? 0);
    }

    public function isExemptFromContributions(?int $month = null, ?int $year = null): bool
    {
        if ($this->migration_status === 'migration_pending') {
            return true;
        }

        if ($this->hasActiveLoanRepaymentObligation()) {
            return true;
        }

        if ($this->hasPartiallyDisbursedLoan()) {
            return true;
        }

        if ($month !== null && $year !== null && $this->isInLoanGracePeriodForCycle($month, $year)) {
            return true;
        }

        return false;
    }

    public function hasActiveLoanRepaymentObligation(): bool
    {
        return Loan::query()
            ->where('member_id', $this->id)
            ->whereIn('status', ['active', 'transferred'])
            ->whereHas('installments', fn ($q) => $q->whereIn('status', ['pending', 'overdue']))
            ->exists();
    }

    public function hasPartiallyDisbursedLoan(): bool
    {
        return Loan::query()
            ->where('member_id', $this->id)
            ->whereIn('status', ['approved', 'partially_disbursed'])
            ->whereRaw('COALESCE(amount_disbursed, 0) < COALESCE(amount_approved, amount_requested, 0)')
            ->exists();
    }

    public function isInLoanGracePeriodForCycle(int $month, int $year): bool
    {
        $cycleStart = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();

        return Loan::query()
            ->where('member_id', $this->id)
            ->whereIn('status', ['active', 'approved'])
            ->where('has_grace_cycle', true)
            ->where(function ($query) use ($cycleStart): void {
                $query->whereNull('first_repayment_month')
                    ->orWhere('first_repayment_year', '>', (int) $cycleStart->year)
                    ->orWhere(function ($inner) use ($cycleStart): void {
                        $inner->where('first_repayment_year', (int) $cycleStart->year)
                            ->where('first_repayment_month', '>', (int) $cycleStart->month);
                    });
            })
            ->exists();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function migrationStubs(): HasMany
    {
        return $this->hasMany(MigrationCycleStub::class);
    }

    /**
     * @return array<string, string>
     */
    public static function migrationStatusOptions(): array
    {
        return [
            'migration_pending' => __('Migration pending'),
            'active' => __('Cleared for active operation'),
        ];
    }

    public static function migrationStatusBadgeColor(?string $state): string
    {
        return match ($state) {
            'migration_pending' => 'warning',
            'active' => 'success',
            default => 'gray',
        };
    }

    public function isMigrationPending(): bool
    {
        return $this->migration_status === 'migration_pending';
    }

    public function monthlyStatements(): HasMany
    {
        return $this->hasMany(MonthlyStatement::class);
    }
}
