<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SuppressesMemberWorkspaceTabBadges
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return null;
    }
}
