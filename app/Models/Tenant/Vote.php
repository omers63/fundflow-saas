<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    public const CHOICE_YES = 'yes';

    public const CHOICE_NO = 'no';

    public const CHOICE_ABSTAIN = 'abstain';

    protected $fillable = [
        'motion_id',
        'member_id',
        'proxy_for_member_id',
        'cast_by_member_id',
        'proxy_note',
        'choice',
        'cast_at',
    ];

    protected function casts(): array
    {
        return [
            'cast_at' => 'datetime',
        ];
    }

    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return array<string, string>
     */
    public static function choiceLabels(): array
    {
        return [
            self::CHOICE_YES => __('Yes'),
            self::CHOICE_NO => __('No'),
            self::CHOICE_ABSTAIN => __('Abstain'),
        ];
    }
}
