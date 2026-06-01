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

            if (static::activePeriodExists((int) $contribution->member_id, $month, $year)) {
                throw ValidationException::withMessages([
                    'period' => [
                        __('A contribution already exists for :period.', [
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
