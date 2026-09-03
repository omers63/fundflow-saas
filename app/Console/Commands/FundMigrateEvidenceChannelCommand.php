<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\EvidenceChannelMigrationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class FundMigrateEvidenceChannelCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:migrate-evidence-channel
        {channel : Target channel: bank_csv, sms, or both}
        {--dry-run : Analyze only; do not change the setting}
        {--force : Apply even when warnings are present}';

    protected $description = 'Analyze or apply an evidence channel switch (bank CSV ↔ SMS)';

    public function handle(EvidenceChannelMigrationService $migration): int
    {
        try {
            $report = $migration->migrate(
                (string) $this->argument('channel'),
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('Current channel: :current → target: :target', [
            'current' => $report['current_channel'],
            'target' => $report['target_channel'],
        ]));

        $this->table(
            [__('Metric'), __('Count')],
            [
                [__('Pending operational rows'), $report['pending_operational_rows']],
                [__('Posted SMS without ops link'), $report['posted_sms_without_ops_link']],
                [__('Posted SMS without bank link'), $report['posted_sms_without_bank_link']],
                [__('Open bank import lines'), $report['open_bank_import_lines']],
                [__('Accepted deposits with uncleared ops'), $report['accepted_deposits_uncleared_ops']],
            ],
        );

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($this->option('dry-run')) {
            $this->info(__('Dry run complete — no setting change applied.'));

            return self::SUCCESS;
        }

        $this->info(__('Evidence channel updated to :channel.', [
            'channel' => $report['target_channel'],
        ]));

        return self::SUCCESS;
    }
}
