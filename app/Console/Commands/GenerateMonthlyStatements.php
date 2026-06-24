<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Models\Tenant\Member;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateMonthlyStatements extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'statements:generate {--period=} {--notify} {--member=}';

    protected $description = 'Generate monthly member statements';

    public function handle(MonthlyStatementService $service): int
    {
        $period = $this->option('period')
            ?: BusinessDay::now()->subMonthNoOverflow()->format('Y-m');

        $notify = (bool) $this->option('notify');
        $memberId = $this->option('member');

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
