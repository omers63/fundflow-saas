<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCloseWaiver extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fiscal_close_id',
        'gate_code',
        'reason',
        'waived_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function fiscalClose(): BelongsTo
    {
        return $this->belongsTo(FiscalClose::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}
