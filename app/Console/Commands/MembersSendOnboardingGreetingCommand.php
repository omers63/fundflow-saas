<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Tenant\MemberOnboardingGreetingService;
use Illuminate\Console\Command;

class MembersSendOnboardingGreetingCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'members:send-onboarding-greeting {--member= : Limit to a single member id}';

    protected $description = 'Send the member onboarding greeting email to active members (post-migration or catch-up)';

    public function handle(MemberOnboardingGreetingService $greetings): int
    {
        $memberOption = $this->option('member');
        $memberId = filled($memberOption) ? (int) $memberOption : null;

        $stats = $greetings->sendToActiveMembers($memberId);

        $this->info(__('Onboarding greeting sent to :notified member(s); skipped :skipped.', [
            'notified' => $stats['notified'],
            'skipped' => $stats['skipped'],
        ]));

        return self::SUCCESS;
    }
}
