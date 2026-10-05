<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotionAttachment extends Model
{
    protected $fillable = [
        'motion_id',
        'path',
        'original_name',
        'uploaded_by',
    ];

    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
