<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Risk\RiskWatchlistDigestService;
use App\Support\BusinessDay;
use App\Support\RiskSettings;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class RiskSendWatchlistDigestCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'risk:send-watchlist-digest
        {--force : Run even when not on the configured weekday}';

    protected $description = 'Send high/critical member risk watch-list digest to tenant administrators';

    public function handle(RiskWatchlistDigestService $digest): int
    {
        $weekday = RiskSettings::watchlistDigestWeekday();
        $today = (int) BusinessDay::now()->dayOfWeekIso; // 1=Mon … 7=Sun

        if (! $this->option('force') && $today !== $weekday) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: watch-list digest runs on weekday :day.', ['day' => $weekday]));

            return self::SUCCESS;
        }

        Filament::setCurrentPanel('tenant');

        $count = $digest->notifyAdminsIfNeeded();

        $this->info(__('Notified :count administrator(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
