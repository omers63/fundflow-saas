<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberCommunicationPreference extends Model
{
    /** @var array<string, list<string>>|null */
    private static ?array $cachedRows = null;

    private static ?int $cachedUserId = null;

    protected $fillable = [
        'user_id',
        'notification_type',
        'channels',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saved(fn (self $model) => self::clearCacheForUser($model->user_id));
        static::deleted(fn (self $model) => self::clearCacheForUser($model->user_id));
    }

    /**
     * @return list<string>
     */
    public static function channelsFor(int $userId, string $type, array $default): array
    {
        $rows = self::rowsForUser($userId);

        return $rows[$type] ?? $default;
    }

    /**
     * @param  list<string>  $channels
     * @param  list<string>  $forced
     */
    public static function saveFor(int $userId, string $type, array $channels, array $forced = []): void
    {
        $effective = array_values(array_unique(array_merge($forced, $channels)));

        static::updateOrCreate(
            ['user_id' => $userId, 'notification_type' => $type],
            ['channels' => $effective],
        );
    }

    public static function clearCacheForUser(int $userId): void
    {
        if (self::$cachedUserId === $userId) {
            self::$cachedUserId = null;
            self::$cachedRows = null;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function rowsForUser(int $userId): array
    {
        if (self::$cachedUserId === $userId && self::$cachedRows !== null) {
            return self::$cachedRows;
        }

        self::$cachedUserId = $userId;
        self::$cachedRows = static::query()
            ->where('user_id', $userId)
            ->get()
            ->mapWithKeys(fn (self $row): array => [
                $row->notification_type => (array) $row->channels,
            ])
            ->all();

        return self::$cachedRows;
    }
}
