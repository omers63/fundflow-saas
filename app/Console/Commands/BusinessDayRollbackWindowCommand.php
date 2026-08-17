<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BusinessDayWindowRollbackService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class BusinessDayRollbackWindowCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'business-day:rollback-window
        {--as-of= : Keep activity on this date; undo later business-day stamps}
        {--dry-run : Inventory and blockers only; do not write}';

    protected $description = 'Undo collections and operational postings after a setback business day';

    public function handle(BusinessDayWindowRollbackService $rollback): int
    {
        $asOf = filled($this->option('as-of'))
            ? Carbon::parse((string) $this->option('as-of'))->startOfDay()
            : BusinessDay::today();

        if ($this->option('dry-run')) {
            $report = $rollback->preview($asOf);
            $this->line($report->summary());

            return $report->blocked ? self::FAILURE : self::SUCCESS;
        }

        try {
            $report = $rollback->execute($asOf);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($report->summary());

        return self::SUCCESS;
    }
}
