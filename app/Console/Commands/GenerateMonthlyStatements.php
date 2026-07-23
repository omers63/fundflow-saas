<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Models\Tenant\Member;
use App\Services\MonthlyStatementService;
use App\Support\AutomationScheduleSettings;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyStatements extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'statements:generate {--period=} {--notify} {--member=} {--force : Run even when not on the configured month-boundary slot}';

    protected $description = 'Generate monthly member statements';

    public function handle(MonthlyStatementService $service): int
    {
        $memberId = $this->option('member');
        $periodForced = filled($this->option('period')) || filled($memberId);

        if (
            ! $this->option('force')
            && ! $periodForced
            && ! AutomationScheduleSettings::isMonthBoundarySlot()
        ) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: statements generate on day :day at 00:30.', [
                'day' => AutomationScheduleSettings::monthBoundaryDay(),
            ]));

            return self::SUCCESS;
        }

        $period = $this->option('period')
            ?: BusinessDay::now()->subMonthNoOverflow()->format('Y-m');

        $notify = (bool) $this->option('notify');

        if ($memberId) {
            $member = Member::query()->findOrFail((int) $memberId);
            $service->generateForMember($member, $period, $notify);
            $this->info(__('Statement generated for member :id.', ['id' => $memberId]));

            return self::SUCCESS;
        }

        $count = $service->generateForAllMembers($period, $notify);

        $this->info(__('Generated :count statement(s) for :period.', [
            'count' => $count,
            'period' => Carbon::createFromFormat('Y-m', $period)->format('F Y'),
        ]));

        return self::SUCCESS;
    }
}
