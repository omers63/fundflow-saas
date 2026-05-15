<?php

namespace App\Models\Tenant;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar_path',
        'preferred_locale',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'tenant') {
            return $this->is_admin;
        }

        if ($panel->getId() === 'member') {
            return $this->member !== null
                && ! in_array($this->member->status, ['suspended', 'withdrawn'], true);
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function preferredLocale(): string
    {
        $locale = (string) ($this->preferred_locale ?? config('app.locale', 'en'));

        return in_array($locale, ['en', 'ar'], true) ? $locale : config('app.locale', 'en');
    }

    public static function normalizePublicDiskRelativePath(?string $raw): ?string
    {
        if (! filled($raw)) {
            return null;
        }

        $path = str_replace('\\', '/', ltrim((string) $raw, '/'));
        while (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }
        if (str_starts_with($path, 'public/')) {
            $path = substr($path, strlen('public/'));
        }

        return filled($path) ? $path : null;
    }

    public function avatarPublicUrl(): ?string
    {
        if (! filled($this->avatar_path)) {
            return null;
        }

        $raw = (string) $this->avatar_path;
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        $path = self::normalizePublicDiskRelativePath($raw);
        if ($path === null) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarPublicUrl();
    }
}
