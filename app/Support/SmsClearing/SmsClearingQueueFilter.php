<?php

declare(strict_types=1);

namespace App\Support\SmsClearing;

use App\Filament\Tenant\Support\SmsClearingTabRegistry;

enum SmsClearingQueueFilter: string
{
    case All = 'all';
    case Unmatched = 'unmatched';
    case ReadyToPost = 'ready_to_post';
    case UnmatchedBank = 'unmatched_bank';
    case ReadyToMatch = 'ready_to_match';
    case UnmatchedOps = 'unmatched_ops';
    case ReadyToClearOps = 'ready_to_clear_ops';

    public static function fromMixed(?string $filter): self
    {
        return match (SmsClearingTabRegistry::normalizeQueueFilter($filter)) {
            SmsClearingTabRegistry::FILTER_UNMATCHED => self::Unmatched,
            SmsClearingTabRegistry::FILTER_READY => self::ReadyToPost,
            SmsClearingTabRegistry::FILTER_UNMATCHED_BANK => self::UnmatchedBank,
            SmsClearingTabRegistry::FILTER_READY_TO_MATCH => self::ReadyToMatch,
            SmsClearingTabRegistry::FILTER_UNMATCHED_OPS => self::UnmatchedOps,
            SmsClearingTabRegistry::FILTER_READY_TO_CLEAR_OPS => self::ReadyToClearOps,
            default => self::All,
        };
    }
}
