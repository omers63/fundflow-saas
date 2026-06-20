<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'statement_date',
        'bank_name',
        'bank_template_id',
        'total_rows',
        'imported_rows',
        'duplicate_rows',
        'status',
        'notes',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'imported_at' => 'datetime',
            'total_rows' => 'integer',
            'imported_rows' => 'integer',
            'duplicate_rows' => 'integer',
        ];
    }

    public function bankTemplate(): BelongsTo
    {
        return $this->belongsTo(BankTemplate::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'pending' => __('Pending'),
            'processing' => __('Processing'),
            'completed' => __('Completed'),
            'failed' => __('Failed'),
        ];
    }

    /**
     * Recompute denormalized row counts from linked bank transactions.
     */
    public function refreshRowCounts(): void
    {
        $query = $this->transactions();

        $total = (clone $query)->count();
        $duplicates = (clone $query)->where('status', 'duplicate')->count();
        $imported = (clone $query)->where('status', '!=', 'duplicate')->count();

        $this->forceFill([
            'total_rows' => $total,
            'imported_rows' => $imported,
            'duplicate_rows' => $duplicates,
        ])->saveQuietly();
    }
}
