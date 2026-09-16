<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\User;
use App\Notifications\Tenant\RiskWatchlistDigestNotification;
use App\Support\RiskSettings;

final class RiskWatchlistDigestService
{
    public function __construct(
        private readonly MlRiskScoreService $risk,
    ) {
    }

    public function notifyAdminsIfNeeded(): int
    {
        if (!RiskSettings::watchlistDigestEnabled()) {
            return 0;
        }

        $watchlist = $this->risk->watchlist('high');

        if ($watchlist === []) {
            return 0;
        }

        $rows = array_map(
            fn(array $row): array => [
                'name' => (string) $row['member']->name,
                'score' => (int) $row['score']['score'],
                'band' => (string) $row['score']['band'],
            ],
            $watchlist,
        );

        $url = MemberResource::getUrl('index', panel: 'tenant');
        $notified = 0;

        User::query()
            ->where('is_admin', true)
            ->each(function (User $user) use ($rows, $url, &$notified): void {
                $user->notify(new RiskWatchlistDigestNotification($rows, $url));
                $notified++;
            });

        return $notified;
    }
}
