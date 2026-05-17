<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    public const TYPE_LOAN = 'loan';

    public const TYPES = ['cash', 'fund', 'bank', 'expense', 'fees', 'invest', 'loan'];

    protected $fillable = [
        'member_id',
        'loan_id',
        'type',
        'name',
        'balance',
        'is_master',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_master' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeMaster($query)
    {
        return $query->where('is_master', true);
    }

    public function scopeMemberAccounts($query)
    {
        return $query->where('is_master', false);
    }

    public static function masterCash(): ?self
    {
        return static::where('is_master', true)->where('type', 'cash')->first();
    }

    public static function masterFund(): ?self
    {
        return static::where('is_master', true)->where('type', 'fund')->first();
    }

    public static function masterBank(): ?self
    {
        return static::where('is_master', true)->where('type', 'bank')->first();
    }

    public static function masterExpense(): ?self
    {
        return static::where('is_master', true)->where('type', 'expense')->first();
    }

    public static function masterFees(): ?self
    {
        return static::where('is_master', true)->where('type', 'fees')->first();
    }

    public static function masterInvest(): ?self
    {
        return static::where('is_master', true)->where('type', 'invest')->first();
    }
}
