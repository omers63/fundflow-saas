<?php

declare(strict_types=1);

namespace App\Support\SmsClearing;

use App\Filament\Tenant\Support\SmsClearingTabRegistry;

enum SmsClearingQueueFilter: string
{
    case All = 'all';
    case Unmatched = 'unmatched';
    case ReadyToPost = 'ready_to_post';

    public static function fromMixed(?string $filter): self
    {
        return match (SmsClearingTabRegistry::normalizeQueueFilter($filter)) {
            SmsClearingTabRegistry::FILTER_UNMATCHED => self::Unmatched,
            SmsClearingTabRegistry::FILTER_READY => self::ReadyToPost,
            default => self::All,
        };
    }
}
