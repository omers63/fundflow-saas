<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Models\Tenant\Member;
use App\Services\ContributionCollectionCycleService;
use Illuminate\Console\Command;

class ContributionsDismissPreCutoffCommand extends Command
{
    use EnsuresBatchPostingAllowed;

    protected $signature = 'contributions:dismiss-pre-cutoff {member_id? : Member ID (optional; all import cut-off members when omitted)}';

    protected $description = 'Dismiss pending contributions before import arrears cut-off and reverse their late fees';

    public function handle(ContributionCollectionCycleService $collection): int
    {
        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        $memberId = $this->argument('member_id');

        $query = Member::query()
            ->whereNotNull('contribution_arrears_cutoff_date');

        if ($memberId !== null) {
            $query->whereKey((int) $memberId);
        }

        $totalDismissed = 0;
        $membersProcessed = 0;

        $query->orderBy('id')->each(function (Member $member) use ($collection, &$totalDismissed, &$membersProcessed): void {
            $dismissed = $collection->dismissPreCutoffPendingContributions($member);

            if ($dismissed > 0) {
                $membersProcessed++;
                $totalDismissed += $dismissed;
                $this->line(__('Member :number — dismissed :count pre-cut-off cycle(s).', [
                    'number' => $member->member_number,
                    'count' => $dismissed,
                ]));
            }
        });

        $this->info(__('Dismissed :count contribution row(s) across :members member(s).', [
            'count' => $totalDismissed,
            'members' => $membersProcessed,
        ]));

        return self::SUCCESS;
    }
}
