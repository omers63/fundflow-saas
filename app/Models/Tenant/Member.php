<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_contribution_amount' => 'decimal:2',
            'joined_at' => 'date',
            'is_separated' => 'boolean',
            'direct_login_enabled' => 'boolean',
        ];
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

    public function repayments(): HasManyThrough
    {
        return $this->hasManyThrough(LoanRepayment::class, Loan::class);
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
        $next = static::query()->count() + 1;

        return 'MEM-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
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
        $membershipMonths = $this->joined_at->diffInMonths(now());
        if ($membershipMonths < 12) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        $missedContributions = $this->contributions()
            ->where('period', '>=', now()->subMonths(3)->startOfMonth())
            ->where('status', '!=', 'posted')
            ->count();

        if ($missedContributions > 0) {
            return false;
        }

        $hasActiveLoans = $this->loans()
            ->whereIn('status', ['approved', 'disbursed', 'repaying'])
            ->exists();

        if ($hasActiveLoans) {
            return false;
        }

        return true;
    }

    public function getFundBalance(): float
    {
        return (float) ($this->fundAccount?->balance ?? 0);
    }

    public function getCashBalance(): float
    {
        return (float) ($this->cashAccount?->balance ?? 0);
    }
}
