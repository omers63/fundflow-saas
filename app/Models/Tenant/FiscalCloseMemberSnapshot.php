<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCloseMemberSnapshot extends Model
{
    protected $fillable = [
        'fiscal_close_id',
        'member_id',
        'cash_balance',
        'fund_balance',
        'opening_cash_before',
        'opening_fund_before',
        'contribution_arrears_json',
        'loans_json',
        'delinquency_json',
        'eligibility_json',
    ];

    protected function casts(): array
    {
        return [
            'cash_balance' => 'decimal:2',
            'fund_balance' => 'decimal:2',
            'opening_cash_before' => 'decimal:2',
            'opening_fund_before' => 'decimal:2',
            'contribution_arrears_json' => 'array',
            'loans_json' => 'array',
            'delinquency_json' => 'array',
            'eligibility_json' => 'array',
        ];
    }

    public function fiscalClose(): BelongsTo
    {
        return $this->belongsTo(FiscalClose::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
