<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\LegacyMigration\LegacyExcessLoanRepaymentRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;

class LegacyRepairExcessLoanRepaymentsCommand extends Command
{
    use HasATenantsOption;
    use TenantAwareCommand;

    protected $signature = 'legacy:repair-excess-loan-repayments
        {--loan= : Loan id to repair}
        {--member= : Member number or database id — repair all of their loans}';

    protected $description = 'Move legacy loan repayments that exceed the fund-portion target back to contributions';

    public function handle(LegacyExcessLoanRepaymentRepairService $service): int
    {
        $loans = $this->resolveLoans();

        if ($loans->isEmpty()) {
            $this->error(__('Pass --loan=<id> or --member=<number|id>.'));

            return self::FAILURE;
        }

        $this->info(__('Repairing :count loan(s)…', ['count' => $loans->count()]));
        $totals = $service->repairLoans($loans);

        $this->table(
            ['Metric', 'Count'],
            collect($totals)->map(fn (int $count, string $key) => [str_replace('_', ' ', $key), $count])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Loan>
     */
    private function resolveLoans(): Collection
    {
        $loanOption = (string) ($this->option('loan') ?? '');

        if ($loanOption !== '') {
            $loan = Loan::query()->find((int) $loanOption);

            return $loan !== null ? collect([$loan]) : collect();
        }

        $memberOption = (string) ($this->option('member') ?? '');

        if ($memberOption === '') {
            return collect();
        }

        $member = is_numeric($memberOption)
            ? Member::query()->find((int) $memberOption)
            : Member::query()->where('member_number', $memberOption)->first();

        if ($member === null) {
            return collect();
        }

        return $member->loans()
            ->whereHas('repayments')
            ->orderBy('disbursed_at')
            ->get();
    }
}
