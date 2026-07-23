<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    public const FAMILY_EMAIL = 'email';

    public const FAMILY_SMS_PUSH = 'sms_push';

    public const FAMILY_IN_APP = 'in_app';

    protected $fillable = [
        'key',
        'locale',
        'channel_family',
        'subject',
        'body_markdown',
    ];

    /**
     * @return list<string>
     */
    public static function channelFamilies(): array
    {
        return [
            self::FAMILY_EMAIL,
            self::FAMILY_SMS_PUSH,
            self::FAMILY_IN_APP,
        ];
    }
}
