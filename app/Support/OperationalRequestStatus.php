<?php

declare(strict_types=1);

namespace App\Support;

final class OperationalRequestStatus
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::PENDING => __('Pending'),
            self::ACCEPTED => __('Accepted'),
            self::REJECTED => __('Rejected'),
            self::CANCELLED => __('Cancelled'),
        ];
    }

    public static function label(string $state): string
    {
        return self::options()[$state] ?? ucfirst($state);
    }

    public static function color(string $state): string
    {
        return match ($state) {
            self::PENDING => 'warning',
            self::ACCEPTED => 'success',
            self::REJECTED => 'danger',
            self::CANCELLED => 'gray',
            default => 'gray',
        };
    }
}
