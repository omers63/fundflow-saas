<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return static::where('group', $group)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function set(string $group, string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->pluck('value', 'key')
            ->all();
    }
}
