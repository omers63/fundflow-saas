<?php

namespace App\Models\Tenant;

use App\Filament\Support\MoneyDisplay;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\LoanService;
use App\Services\MemberMonthlyAllocationService;
use App\Support\ContributionExemptionPolicy;
use App\Support\MemberMembershipPolicy;
use App\Support\MemberNumberSettings;
use Illuminate\Database\Eloquent\Builder;
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

    private static bool $bypassSelfAllocationGuard = false;

    /** @var list<int> */
    public const CONTRIBUTION_STEPS = [500, 1000, 1500, 2000, 2500, 3000];

    /** @var list<string> */
    public const STATUSES = ['active', 'inactive', 'withdrawn'];

    /** @var list<string> */
    public const PORTAL_BLOCKED_STATUSES = MemberMembershipPolicy::PORTAL_BLOCKED_STATUSES;

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
        'exclude_from_household_contribution_funding',
        'joined_at',
        'contribution_arrears_cutoff_date',
        'opening_cash_balance',
        'opening_fund_balance',
        'opening_balances_posted_at',
        'status',
        'contribution_cycles_active',
        'payout_frozen_at',
        'status_reason',
        'status_changed_at',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'monthly_contribution_amount' => 'decimal:2',
            'exclude_from_household_contribution_funding' => 'boolean',
            'joined_at' => 'date',
            'contribution_arrears_cutoff_date' => 'date',
            'opening_cash_balance' => 'decimal:2',
            'opening_fund_balance' => 'decimal:2',
            'opening_balances_posted_at' => 'datetime',
            'contribution_cycles_active' => 'boolean',
            'payout_frozen_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'frozen_at' => 'datetime',
            'is_separated' => 'boolean',
            'direct_login_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Member $member): void {
            if (! $member->isDirty('monthly_contribution_amount') || self::$bypassSelfAllocationGuard) {
                return;
            }

            app(MemberMonthlyAllocationService::class)->assertCanSelfChangeMonthlyContribution($member);
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutSelfAllocationGuard(callable $callback): mixed
    {
        self::$bypassSelfAllocationGuard = true;

        try {
            return $callback();
        } finally {
            self::$bypassSelfAllocationGuard = false;
        }
    }

    public function isParent(): bool
    {
        return $this->parent_member_id === null;
    }

    public function isSponsoredDependent(): bool
    {
        return $this->parent_member_id !== null;
    }

    public function householdHead(): self
    {
        if ($this->isParent()) {
            return $this;
        }

        $parent = $this->relationLoaded('parent')
            ? $this->parent
            : $this->parent()->first();

        return $parent ?? $this;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_member_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_member_id');
    }

    public function allocationChangesReceived(): HasMany
    {
        return $this->hasMany(DependentAllocationChange::class, 'dependent_member_id');
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

    public function guaranteedLoans(): HasMany
    {
        return $this->hasMany(Loan::class, 'guarantor_member_id');
    }

    public function fundPostings(): HasMany
    {
        return $this->hasMany(FundPosting::class);
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

    public function scopeContributionCycleEligible(Builder $query): Builder
    {
        return $query
            ->where('monthly_contribution_amount', '>', 0)
            ->where(function (Builder $subQuery): void {
                $subQuery->where('status', 'active')
                    ->orWhere(function (Builder $inner): void {
                        $inner->where('status', 'inactive')
                            ->where('contribution_cycles_active', true);
                    });
            });
    }

    public function scopeCollectibleForContributionPeriod(Builder $query, int $month, int $year): Builder
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();

        return $query->where(function (Builder $inner) use ($periodStart): void {
            $inner
                ->whereNotNull('contribution_arrears_cutoff_date')
                ->whereRaw('DATE_FORMAT(contribution_arrears_cutoff_date, "%Y-%m-01") <= ?', [$periodStart->toDateString()])
                ->orWhere(function (Builder $fallback) use ($periodStart): void {
                    $fallback
                        ->whereNull('contribution_arrears_cutoff_date')
                        ->where(function (Builder $joined) use ($periodStart): void {
                            $joined
                                ->whereNull('joined_at')
                                ->orWhereDate('joined_at', '<=', $periodStart->copy()->endOfMonth()->toDateString());
                        });
                });
        });
    }

    public function scopeActiveWithZeroCash($query)
    {
        return $query
            ->active()
            ->whereHas('accounts', function ($query): void {
                $query->where('type', 'cash')
                    ->where('is_master', false)
                    ->where('balance', '<=', 0);
            });
    }

    public function scopeWithParent($query)
    {
        return $query->whereNotNull('parent_member_id');
    }

    public function scopeIndependent($query)
    {
        return $query->whereNull('parent_member_id');
    }

    public function scopeOrderByCashBalance($query, string $direction = 'asc')
    {
        return $query->orderBy(
            Account::query()
                ->select('balance')
                ->whereColumn('accounts.member_id', 'members.id')
                ->where('type', 'cash')
                ->where('is_master', false)
                ->limit(1),
            $direction,
        );
    }

    public function scopeOrderByFundBalance($query, string $direction = 'asc')
    {
        return $query->orderBy(
            Account::query()
                ->select('balance')
                ->whereColumn('accounts.member_id', 'members.id')
                ->where('type', 'fund')
                ->where('is_master', false)
                ->limit(1),
            $direction,
        );
    }

    public static function generateMemberNumber(): string
    {
        return MemberNumberSettings::generate();
    }

    public static function isValidContributionAmount(int $amount): bool
    {
        return in_array($amount, self::CONTRIBUTION_STEPS, true);
    }

    public static function isValidDependentContributionAmount(int $amount): bool
    {
        return self::isValidContributionAmount($amount);
    }

    /**
     * @return array<int, string>
     */
    public static function contributionAmountOptions(): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $options = [];

        foreach (self::CONTRIBUTION_STEPS as $amount) {
            $options[$amount] = MoneyDisplay::format($amount, $currency, precision: 0) ?? '';
        }

        return $options;
    }

    /**
     * Dependent contribution amount options (same steps as members: 500–3000).
     * Household funding exclusion is a separate flag.
     *
     * @return array<int, string>
     */
    public static function dependentContributionAmountOptions(): array
    {
        return self::contributionAmountOptions();
    }

    public function excludesHouseholdContributionFunding(): bool
    {
        return (bool) $this->exclude_from_household_contribution_funding;
    }

    public function isFundedByParent(): bool
    {
        return ! $this->excludesHouseholdContributionFunding();
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'active' => __('Active'),
            'inactive' => __('Inactive'),
            'withdrawn' => __('Withdrawn'),
        ];
    }

    public static function statusBadgeColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'inactive' => 'gray',
            'withdrawn' => 'danger',
            default => 'gray',
        };
    }

    public function adminStatusBadgeColor(): string
    {
        if ($this->status === 'active' && app(LoanDelinquencyService::class)->isDelinquent($this)) {
            return 'warning';
        }

        return self::statusBadgeColor((string) $this->status);
    }

    public function adminStatusLabel(): string
    {
        $label = self::statusOptions()[(string) $this->status] ?? ucfirst((string) $this->status);

        if ($this->status === 'active' && app(LoanDelinquencyService::class)->isDelinquent($this)) {
            return $label.' · '.__('arrears');
        }

        if ($this->status === 'inactive' && $this->frozen_at !== null) {
            return $label.' · '.__('frozen');
        }

        if ($this->status === 'withdrawn' && $this->payout_frozen_at !== null) {
            return $label.' · '.__('payout hold');
        }

        return $label;
    }

    public static function portalBlockedSessionKey(string $status): string
    {
        return match ($status) {
            'inactive' => 'member_inactive_notice',
            'withdrawn' => 'member_withdrawn_notice',
            default => 'member_delinquent_notice',
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
        $policy = app(ContributionExemptionPolicy::class);

        if ($month === null || $year === null) {
            return $policy->isContributionExemptNow($this);
        }

        return $policy->isContributionExemptForCycle($this, $month, $year);
    }

    public function isInActiveLoanContributionExemptCycle(int $month, int $year): bool
    {
        return app(ContributionExemptionPolicy::class)
            ->memberIsInEmiRepaymentPhase($this, $month, $year);
    }

    /**
     * Whether the member was in a loan repayment cycle during the labelled contribution period
     * (contributions are not expected; only loan installment obligations apply).
     */
    public function wasInLoanRepaymentCycle(int $month, int $year): bool
    {
        return $this->isInActiveLoanContributionExemptCycle($month, $year);
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
        return app(ContributionExemptionPolicy::class)
            ->memberIsInGraceCycle($this, $month, $year);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function monthlyStatements(): HasMany
    {
        return $this->hasMany(MonthlyStatement::class);
    }
}
