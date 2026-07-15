<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

final class WaitingDuration
{
    public static function format(?CarbonInterface $since, ?CarbonInterface $until = null): string
    {
        if ($since === null) {
            return '—';
        }

        $until ??= BusinessDay::now();
        $months = (int) $since->diffInMonths($until);
        $days = (int) $since->copy()->addMonths($months)->diffInDays($until);

        if ($months === 0) {
            return trans_choice(':count day|:count days', $days, ['count' => $days]);
        }

        if ($days === 0) {
            return trans_choice(':count month|:count months', $months, ['count' => $months]);
        }

        return __(':months, :days', [
            'months' => trans_choice(':count month|:count months', $months, ['count' => $months]),
            'days' => trans_choice(':count day|:count days', $days, ['count' => $days]),
        ]);
    }

    public static function days(?CarbonInterface $since, ?CarbonInterface $until = null): int
    {
        if ($since === null) {
            return 0;
        }

        $until ??= BusinessDay::now();

        return (int) $since->diffInDays($until);
    }
}
