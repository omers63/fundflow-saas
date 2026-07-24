<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Tenant\MemberOnboardingGreetingService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class MembersSendOnboardingGreetingCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'members:send-onboarding-greeting
        {--member= : Limit to a single member id}
        {--force : Run even when not in the configured onboarding greeting slot}';

    protected $description = 'Send the member onboarding greeting email to active members (post-migration or catch-up)';

    public function handle(MemberOnboardingGreetingService $greetings): int
    {
        $memberOption = $this->option('member');
        $memberForced = filled($memberOption);

        if (! $this->option('force') && ! $memberForced && ! AutomationScheduleSettings::isOnboardingGreetingSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: onboarding greeting catch-up is disabled or not at :time.', [
                'time' => AutomationScheduleSettings::onboardingGreetingTime(),
            ]));

            return self::SUCCESS;
        }

        if (! AutomationScheduleSettings::notifyOnboardingGreeting() && ! $this->option('force') && ! $memberForced) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: onboarding greeting notifications are disabled in automation settings.'));

            return self::SUCCESS;
        }

        $memberId = $memberForced ? (int) $memberOption : null;

        $stats = $greetings->sendToActiveMembers($memberId);

        $this->info(__('Onboarding greeting sent to :notified member(s); skipped :skipped.', [
            'notified' => $stats['notified'],
            'skipped' => $stats['skipped'],
        ]));

        return self::SUCCESS;
    }
}
