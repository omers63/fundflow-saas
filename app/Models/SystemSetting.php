<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'app_name',
        'support_email',
        'public_hero_title',
        'public_hero_subtitle',
        'public_primary_color',
        'public_secondary_color',
        'admin_primary_color',
        'member_primary_color',
        'maintenance_enabled',
        'maintenance_message',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_enabled' => 'boolean',
        ];
    }
}
