<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsImportSession extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bank_name',
        'template_id',
        'imported_by',
        'filename',
        'file_path',
        'status',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'error_count',
        'notes',
        'error_log',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'error_log' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsImportTemplate::class, 'template_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SmsTransaction::class, 'import_session_id');
    }
}
