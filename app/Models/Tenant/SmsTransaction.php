<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bank_name',
        'import_session_id',
        'member_id',
        'transaction_date',
        'amount',
        'transaction_type',
        'reference',
        'raw_sms',
        'raw_data',
        'posted_at',
        'posted_by',
        'sms_clearance_match_group_id',
        'is_bank_cleared',
        'bank_cleared_at',
        'sms_ops_clearance_match_group_id',
        'is_ops_cleared',
        'ops_cleared_at',
        'master_bank_transaction_id',
        'is_duplicate',
        'duplicate_of_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'is_duplicate' => 'boolean',
            'is_bank_cleared' => 'boolean',
            'is_ops_cleared' => 'boolean',
            'raw_data' => 'array',
            'posted_at' => 'datetime',
            'bank_cleared_at' => 'datetime',
            'ops_cleared_at' => 'datetime',
        ];
    }

    public function importSession(): BelongsTo
    {
        return $this->belongsTo(SmsImportSession::class, 'import_session_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(SmsTransaction::class, 'duplicate_of_id');
    }

    public function smsClearanceMatchGroup(): BelongsTo
    {
        return $this->belongsTo(SmsClearanceMatchGroup::class);
    }

    public function smsOpsClearanceMatchGroup(): BelongsTo
    {
        return $this->belongsTo(SmsOpsClearanceMatchGroup::class);
    }

    public function masterBankTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'master_bank_transaction_id');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }
}
