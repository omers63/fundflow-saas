<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Services\Tenant\MemberAudienceResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAnnouncement extends Model
{
    public const AUDIENCE_ALL_ACTIVE = 'all_active';

    public const AUDIENCE_OVERDUE = 'overdue';

    public const AUDIENCE_DELINQUENT = 'delinquent';

    public const AUDIENCE_WITH_ACTIVE_LOANS = 'with_active_loans';

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_EMAIL = 'email';

    protected $fillable = [
        'created_by_user_id',
        'audience',
        'title_en',
        'title_ar',
        'body_en',
        'body_ar',
        'channels',
        'recipient_count',
        'delivered_count',
        'scheduled_for',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    public static function audienceOptions(): array
    {
        return MemberAudienceResolver::announcementOptions();
    }

    /**
     * @return array<string, string>
     */
    public static function channelOptions(): array
    {
        return [
            self::CHANNEL_IN_APP => __('In-app alert'),
            self::CHANNEL_SMS => __('SMS'),
            self::CHANNEL_EMAIL => __('Email'),
        ];
    }
}
