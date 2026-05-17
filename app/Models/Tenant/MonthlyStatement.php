<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonthlyStatement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'member_id',
        'period',
        'opening_balance',
        'total_contributions',
        'total_repayments',
        'closing_balance',
        'generated_at',
        'details',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'total_contributions' => 'decimal:2',
            'total_repayments' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'generated_at' => 'datetime',
            'notified_at' => 'datetime',
            'details' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function upsertForMember(int $memberId, string $period, array $values): self
    {
        $row = static::withTrashed()
            ->where('member_id', $memberId)
            ->where('period', $period)
            ->first();

        if ($row !== null) {
            if ($row->trashed()) {
                $row->restore();
            }
            $row->fill($values);
            $row->save();

            return $row;
        }

        return static::create(array_merge($values, [
            'member_id' => $memberId,
            'period' => $period,
        ]));
    }

    public function getPeriodFormattedAttribute(): string
    {
        $parts = explode('-', $this->period);

        return Carbon::create((int) $parts[0], (int) $parts[1], 1)
            ->locale(app()->getLocale())
            ->translatedFormat('F Y');
    }
}
