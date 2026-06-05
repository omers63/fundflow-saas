<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\AccountingService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Contribution extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const PAYMENT_METHOD_ADMIN = 'admin';

    public const PAYMENT_METHOD_CASH_ACCOUNT = 'cash_account';

    public const PAYMENT_METHOD_IMPORT_CSV = 'import_csv';

    protected $fillable = [
        'member_id',
        'period',
        'amount',
        'status',
        'posted_at',
        'paid_at',
        'payment_method',
        'reference_number',
        'notes',
        'is_late',
        'late_fee_amount',
        'collection_status',
        'amount_due',
        'amount_collected',
        'overdue_since',
        'late_fee_tier',
        'cycle_open_cash_balance',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'late_fee_amount' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'amount_collected' => 'decimal:2',
            'cycle_open_cash_balance' => 'decimal:2',
            'overdue_since' => 'datetime',
            'posted_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contribution $contribution): void {
            $member = Member::query()->find((int) $contribution->member_id);

            if ($member && $member->isExemptFromContributions()) {
                throw ValidationException::withMessages([
                    'member_id' => [__('This member has an active loan with pending repayments. Keep funds in cash until installments are paid.')],
                ]);
            }

            if ($contribution->period === null) {
                return;
            }

            $month = (int) $contribution->period->month;
            $year = (int) $contribution->period->year;

            if (static::memberPeriodRecordExists((int) $contribution->member_id, $month, $year)) {
                $existing = static::findForMemberPeriod((int) $contribution->member_id, $month, $year, withTrashed: true);

                throw ValidationException::withMessages([
                    'period' => [
                        $existing?->trashed()
                        ? __('A deleted contribution still occupies :period. Restore it or permanently remove it before creating a new one.', [
                            'period' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
                        ])
                        : __('A contribution already exists for :period.', [
                            'period' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
                        ]),
                    ],
                ]);
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'reference');
    }

    /**
     * Principal collected from member cash toward amount_due (partial or full settlement).
     */
    public function principalCollectedAmount(): float
    {
        return (float) ($this->amount_collected ?? 0);
    }

    /**
     * Late fees already debited from member cash for this contribution cycle.
     */
    public function lateFeeCollectedAmount(): float
    {
        if (array_key_exists('late_fee_collected_amount', $this->attributes)) {
            return (float) $this->attributes['late_fee_collected_amount'];
        }

        return app(AccountingService::class)->contributionLateFeeCollectedAmount($this);
    }

    /**
     * Eager-load {@see self::lateFeeCollectedAmount()} for tables (member cash debits only).
     *
     * @param  Builder<Contribution>  $query
     * @return Builder<Contribution>
     */
    public function scopeWithLateFeeCollectedAmountSum(Builder $query): Builder
    {
        $descriptionPrefix = __('Contribution late fee —');

        return $query->withSum([
            'transactions as late_fee_collected_amount' => static function (Builder $transactionQuery) use ($descriptionPrefix): void {
                $transactionQuery
                    ->where('type', 'debit')
                    ->where('description', 'like', $descriptionPrefix.'%')
                    ->whereHas('account', static function (Builder $accountQuery): void {
                        $accountQuery
                            ->where('type', 'cash')
                            ->where('is_master', false)
                            ->whereColumn('accounts.member_id', 'contributions.member_id');
                    });
            },
        ], 'amount');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeForPeriod(Builder $query, int $month, int $year): Builder
    {
        return $query->where('period', static::periodDate($month, $year));
    }

    public static function periodDate(int $month, int $year): string
    {
        return Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
    }

    /**
     * Canonical Y-m-d period key for grouping and lookups (matches {@see periodDate()}).
     */
    public static function normalizePeriodKey(mixed $period): ?string
    {
        if ($period === null || $period === '') {
            return null;
        }

        [$month, $year] = self::monthYearFromPeriod($period);

        return self::periodDate($month, $year);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function monthYearFromPeriod(string|\DateTimeInterface $period): array
    {
        $date = Carbon::parse($period)->startOfMonth();

        return [(int) $date->month, (int) $date->year];
    }

    public static function memberPeriodRecordExists(int $memberId, int $month, int $year, bool $withTrashed = true): bool
    {
        $query = static::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->where('member_id', $memberId)
            ->forPeriod($month, $year)
            ->exists();
    }

    public static function findForMemberPeriod(int $memberId, int $month, int $year, bool $withTrashed = false): ?self
    {
        $query = static::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->where('member_id', $memberId)
            ->forPeriod($month, $year)
            ->first();
    }

    public static function activePeriodExists(int $memberId, int $month, int $year): bool
    {
        return static::query()
            ->where('member_id', $memberId)
            ->forPeriod($month, $year)
            ->where(function ($query): void {
                $query->where('status', 'posted')
                    ->orWhere('collection_status', ContributionCollectionStatus::COLLECTED);
            })
            ->exists();
    }

    /**
     * Whether a posted/collected contribution blocks loan repayment for the same cycle.
     * Members exempt from contributions (e.g. active loan EMI) may still repay installments.
     */
    public static function blocksLoanRepaymentForMemberPeriod(Member $member, int $month, int $year): bool
    {
        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        return self::activePeriodExists((int) $member->id, $month, $year);
    }

    public static function periodFullyPosted(int $memberId, int $month, int $year): bool
    {
        return static::query()
            ->where('member_id', $memberId)
            ->forPeriod($month, $year)
            ->posted()
            ->exists();
    }

    public function isSystemGenerated(): bool
    {
        return in_array($this->payment_method, [
            self::PAYMENT_METHOD_CASH_ACCOUNT,
            self::PAYMENT_METHOD_IMPORT_CSV,
        ], true);
    }

    public function isDeletableByAdmin(): bool
    {
        return ! $this->isSystemGenerated();
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMethodOptions(): array
    {
        return [
            self::PAYMENT_METHOD_CASH_ACCOUNT => __('Cash account (cycle)'),
            self::PAYMENT_METHOD_ADMIN => __('Admin entry'),
            self::PAYMENT_METHOD_IMPORT_CSV => __('CSV import'),
        ];
    }
}
