<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Member;
use App\Services\Tenant\HouseholdMemberService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class DetachInvalidHouseholdDependentsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'household:detach-invalid-dependents {--dry-run : Report members that would be detached without changing data}';

    protected $description = 'Detach dependents whose email differs from the household parent or who are marked separated';

    public function handle(HouseholdMemberService $householdMembers): int
    {
        if ($this->option('dry-run')) {
            $count = 0;

            Member::query()
                ->whereNotNull('parent_member_id')
                ->with('parent')
                ->orderBy('id')
                ->each(function (Member $member) use (&$count): void {
                    $parent = $member->parent;

                    if ($parent === null) {
                        return;
                    }

                    $parentHouseholdEmail = strtolower(trim((string) ($parent->household_email ?? $parent->email ?? '')));
                    $contactEmail = strtolower(trim((string) ($member->email ?? '')));

                    if ($member->is_separated || ($parentHouseholdEmail !== '' && $contactEmail !== $parentHouseholdEmail)) {
                        $count++;
                        $this->line(sprintf(
                            'Would detach #%d %s (parent #%d)',
                            $member->id,
                            $member->name,
                            $parent->id,
                        ));
                    }
                });

            $this->info("Dry run complete: {$count} dependent(s) would be detached.");

            return self::SUCCESS;
        }

        $detached = $householdMembers->detachInvalidDependents();

        $this->info(sprintf('Detached %d dependent(s) from their household parent.', count($detached)));

        foreach ($detached as $member) {
            $this->line(sprintf('#%d %s', $member->id, $member->name));
        }

        return self::SUCCESS;
    }
}
