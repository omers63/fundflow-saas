<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Models\Tenant\MemberSavingsGoal;
use App\Notifications\Tenant\GenericMemberAlertNotification;
use App\Services\Savings\MemberSavingsGoalService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Facades\Tenancy;

class NudgeBehindSavingsGoalsCommand extends Command
{
    protected $signature = 'savings:nudge-behind-goals {--tenant= : Tenant id}';

    protected $description = 'Notify members whose active savings goals are behind schedule.';

    public function handle(MemberSavingsGoalService $goals): int
    {
        $tenantId = $this->option('tenant');
        $query = Tenant::query()->where('is_provisioned', true);
        if (is_string($tenantId) && $tenantId !== '') {
            $query->whereKey($tenantId);
        }

        $notified = 0;

        $query->each(function (Tenant $tenant) use ($goals, &$notified): void {
            Tenancy::initialize($tenant);

            try {
                MemberSavingsGoal::query()
                    ->with('member.user')
                    ->where('status', MemberSavingsGoal::STATUS_ACTIVE)
                    ->orderBy('id')
                    ->each(function (MemberSavingsGoal $goal) use ($goals, &$notified): void {
                        $progress = $goals->progress($goal);
                        if (! $progress['behind_schedule']) {
                            return;
                        }

                        $user = $goal->member?->user;
                        if ($user === null) {
                            return;
                        }

                        $user->notify(new GenericMemberAlertNotification(
                            title: __('Savings goal behind schedule'),
                            body: __('“:title” is behind your target date. Consider increasing your monthly contribution.', [
                                'title' => $goal->title,
                            ]),
                        ));
                        $notified++;
                    });
            } finally {
                Tenancy::end();
            }
        });

        $this->info("Notified {$notified} member(s).");

        return self::SUCCESS;
    }
}
