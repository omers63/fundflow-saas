<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Member;
use App\Services\LegacyMigration\LegacyMisclassifiedContributionRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRepairMisclassifiedContributionsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:repair-misclassified-contributions
        {--member= : Member number or database id to repair}
        {--delinquent : Repair all delinquent members}';

    protected $description = 'Convert legacy contributions that should have been loan repayments (monthly amount below tier EMI at cycle start)';

    public function handle(LegacyMisclassifiedContributionRepairService $service): int
    {
        $members = $this->resolveMembers();

        if ($members->isEmpty()) {
            $this->error(__('Pass --member=<number|id> or --delinquent.'));

            return self::FAILURE;
        }

        $this->info(__('Repairing :count member(s)…', ['count' => $members->count()]));

        $totals = $service->repairMembers($members);

        $this->table(
            ['Metric', 'Count'],
            collect($totals)->map(fn (int $count, string $key) => [str_replace('_', ' ', $key), $count])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Member>
     */
    private function resolveMembers(): Collection
    {
        if ($this->option('delinquent')) {
            return Member::query()->where('status', 'delinquent')->orderBy('member_number')->get();
        }

        $memberOption = (string) ($this->option('member') ?? '');

        if ($memberOption === '') {
            return collect();
        }

        $member = is_numeric($memberOption)
            ? Member::query()->find((int) $memberOption)
            : Member::query()->where('member_number', $memberOption)->first();

        return $member !== null ? collect([$member]) : collect();
    }
}
