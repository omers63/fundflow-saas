<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Member;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\AssociativeCsv;
use App\Support\LegacyMemberIdentifierResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;
use Throwable;

class RelinkHouseholdsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'households:relink
        {--path= : CSV with member_number and parent_member_number columns (defaults to the legacy migration working members CSV)}
        {--dry-run : Report the links that would be created without changing data}';

    protected $description = 'Backfill parent_member_id on existing members from a parent_member_number column, without re-importing';

    public function handle(
        HouseholdMemberService $householdMembers,
        LegacyMemberIdentifierResolver $resolver,
    ): int {
        $path = $this->resolveCsvPath();

        if ($path === null) {
            $this->error('No CSV found. Pass --path=/absolute/file.csv or upload a members CSV in the legacy migration workspace first.');

            return self::FAILURE;
        }

        $headers = AssociativeCsv::headers($path);

        foreach (['member_number', 'parent_member_number'] as $required) {
            if (! in_array($required, $headers, true)) {
                $this->error("The CSV must include a '{$required}' column. Found: ".implode(', ', $headers));

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $linked = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;

        foreach (AssociativeCsv::read($path) as $row) {
            $memberNumber = trim((string) ($row['member_number'] ?? ''));
            $parentNumber = trim((string) ($row['parent_member_number'] ?? ''));

            if ($memberNumber === '' || $parentNumber === '') {
                continue;
            }

            $dependent = $resolver->findByMemberNumber($memberNumber);

            if ($dependent === null) {
                $failed++;
                $this->line("FAILED  #{$memberNumber}: member not found");

                continue;
            }

            $parent = $resolver->findByNumberOrLegacyLabel($parentNumber);

            if ($parent === null) {
                $failed++;
                $this->line("FAILED  #{$memberNumber}: parent '{$parentNumber}' not found");

                continue;
            }

            if ((int) $parent->id === (int) $dependent->id) {
                $skipped++;

                continue;
            }

            if ((int) $dependent->parent_member_id === (int) $parent->id) {
                $unchanged++;

                continue;
            }

            if ($dryRun) {
                $linked++;
                $this->line(sprintf(
                    'LINK    #%s %s -> parent #%s %s',
                    $dependent->member_number,
                    $dependent->name,
                    $parent->member_number,
                    $parent->name,
                ));

                continue;
            }

            try {
                $householdMembers->assignToHousehold($dependent, $parent, $this->householdEmailFor($parent));
                $linked++;
                $this->line(sprintf('LINKED  #%s -> parent #%s', $dependent->member_number, $parent->member_number));
            } catch (Throwable $exception) {
                $failed++;
                $this->line(sprintf('FAILED  #%s -> parent #%s: %s', $dependent->member_number, $parent->member_number, $exception->getMessage()));
            }
        }

        $verb = $dryRun ? 'Would link' : 'Linked';
        $this->info("{$verb} {$linked} dependent(s). Unchanged: {$unchanged}. Skipped (self): {$skipped}. Failed: {$failed}.");

        return $failed > 0 && $linked === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveCsvPath(): ?string
    {
        $option = $this->option('path');

        if (is_string($option) && $option !== '') {
            return is_readable($option) ? $option : null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE)) {
            return null;
        }

        $absolute = $disk->path(LegacyMigrationWorkingCopy::MEMBERS_RELATIVE);

        return is_readable($absolute) ? $absolute : null;
    }

    private function householdEmailFor(Member $parent): string
    {
        return strtolower(trim((string) ($parent->household_email ?? $parent->email ?? '')));
    }
}
