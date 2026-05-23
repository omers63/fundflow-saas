<?php

declare(strict_types=1);

namespace App\Support;

final class InstallmentCollectionStatus
{
    public const PENDING = 'pending';

    public const PARTIALLY_PENDING = 'partially_pending';

    public const COLLECTED = 'collected';

    public const OVERDUE = 'overdue';

    /**
     * @return list<string>
     */
    public static function openCollectionStates(): array
    {
        return [
            self::PENDING,
            self::PARTIALLY_PENDING,
            self::OVERDUE,
        ];
    }
}
