<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant\Member;
use App\Services\MigrationCycleService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MigrationGenerateStubsCommand extends Command
{
    protected $signature = 'migration:generate-stubs {member_id} {--cutoff=}';

    protected $description = 'Generate historical migration cycle stubs for a member';

    public function handle(MigrationCycleService $migration): int
    {
        $member = Member::query()->findOrFail((int) $this->argument('member_id'));
        $cutoff = $this->option('cutoff')
            ? Carbon::parse((string) $this->option('cutoff'))
            : null;

        try {
            $count = $migration->generateHistoricalStubs($member, $cutoff);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__(':count stub(s) created for :name.', [
            'count' => $count,
            'name' => $member->name,
        ]));

        return self::SUCCESS;
    }
}
