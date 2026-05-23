<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MigrationCycleService
{
    public function __construct(
        protected FundAuditLogService $audit,
        protected ContributionCycleService $cycles,
    ) {
    }

    public function generateHistoricalStubs(Member $member, ?Carbon $cutoff = null): int
    {
        $cutoff ??= $member->migration_cutoff_date
            ? Carbon::parse($member->migration_cutoff_date)
            : now()->startOfMonth();

        $join = $member->joined_at ?? $member->created_at ?? now();

        if ($cutoff->lt($join)) {
            throw new InvalidArgumentException(__('Migration cutoff must be on or after the member join date.'));
        }

        $created = 0;
        $joinDate = Carbon::parse($join);
        $month = (int) $joinDate->month;
        $year = (int) $joinDate->year;

        while (true) {
            $cycleDate = $this->cycles->cycleStartAt($month, $year);

            if ($cycleDate->gt($cutoff)) {
                break;
            }

            $exists = MigrationCycleStub::query()
                ->where('member_id', $member->id)
                ->where('cycle_date', $cycleDate->toDateString())
                ->exists();

            if (!$exists) {
                MigrationCycleStub::create([
                    'member_id' => $member->id,
                    'cycle_date' => $cycleDate->toDateString(),
                    'amount_due' => (float) $member->monthly_contribution_amount,
                    'status' => MigrationCycleStub::STATUS_UNRESOLVED,
                    'late_fee_exempt' => true,
                ]);
                $created++;
            }

            $nextPeriod = Carbon::create($year, $month, 1)->addMonthNoOverflow();
            $month = (int) $nextPeriod->month;
            $year = (int) $nextPeriod->year;
        }

        $member->update([
            'migration_cutoff_date' => $cutoff->toDateString(),
            'migration_status' => 'migration_pending',
        ]);

        $this->audit->log('MIGRATION_STUBS_GENERATED', 'migration', $member, $member, [
            'count' => $created,
            'cutoff' => $cutoff->toDateString(),
        ]);

        return $created;
    }

    public function classifyStub(
        MigrationCycleStub $stub,
        string $classification,
        ?string $resolutionMethod = null,
        ?string $notes = null,
        ?int $classifiedBy = null,
    ): void {
        $stub->update([
            'classification' => $classification,
            'resolution_method' => $resolutionMethod,
            'notes' => $notes,
            'classified_at' => now(),
            'classified_by' => $classifiedBy,
            'status' => in_array($classification, [
                MigrationCycleStub::CLASS_WAIVED,
                MigrationCycleStub::CLASS_BACKDATED_PAID,
                MigrationCycleStub::CLASS_OB_ABSORBED,
            ], true) ? 'closed' : ($classification === MigrationCycleStub::CLASS_BACKDATED_DUE
                ? MigrationCycleStub::STATUS_UNRESOLVED
                : ($classification === MigrationCycleStub::CLASS_ESCALATED ? 'escalated' : MigrationCycleStub::STATUS_UNRESOLVED)),
        ]);

        $this->audit->log('MIGRATION_STUB_CLASSIFIED', 'migration', $stub, $stub->member, [
            'classification' => $classification,
        ]);
    }

    /**
     * @param  iterable<int, MigrationCycleStub>  $stubs
     */
    public function classifyStubs(
        iterable $stubs,
        string $classification,
        ?string $resolutionMethod = null,
        ?string $notes = null,
        ?int $classifiedBy = null,
    ): int {
        $count = 0;

        foreach ($stubs as $stub) {
            if (!$stub instanceof MigrationCycleStub) {
                continue;
            }

            if ($stub->status === 'closed') {
                continue;
            }

            $this->classifyStub($stub, $classification, $resolutionMethod, $notes, $classifiedBy);
            $count++;
        }

        return $count;
    }

    public function grantPartialClearance(Member $member, string $notes): void
    {
        if ($member->migration_status !== 'migration_pending') {
            throw new InvalidArgumentException(__('Partial clearance applies only to migration-pending members.'));
        }

        $member->update([
            'migration_status' => 'active',
            'partial_clearance_granted_at' => now(),
            'partial_clearance_notes' => $notes,
        ]);

        $this->audit->log('MIGRATION_PARTIAL_CLEARANCE', 'migration', $member, $member, [
            'notes' => $notes,
        ]);
    }

    public function hasPartialClearance(Member $member): bool
    {
        return $member->partial_clearance_granted_at !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function clearMemberForActiveOperation(Member $member): void
    {
        $unresolved = MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->unresolved()
            ->count();

        if ($unresolved > 0) {
            throw new InvalidArgumentException(__(':count migration cycle stub(s) still unresolved.', ['count' => $unresolved]));
        }

        $openEscalated = MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->where('classification', MigrationCycleStub::CLASS_ESCALATED)
            ->where('status', '!=', 'closed')
            ->exists();

        if ($openEscalated && !$this->hasPartialClearance($member)) {
            throw new InvalidArgumentException(__('Escalated migration cycles must be closed before clearance, or grant partial clearance.'));
        }

        $unresolvedBackdated = MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
            ->whereNull('resolution_method')
            ->exists();

        if ($unresolvedBackdated) {
            throw new InvalidArgumentException(__('All backdated due cycles need a resolution method before clearance.'));
        }

        $member->update([
            'migration_status' => 'active',
            'partial_clearance_granted_at' => null,
            'partial_clearance_notes' => null,
        ]);

        $this->audit->log('MIGRATION_CLEARANCE', 'migration', $member, $member);
    }

    public function memberIsMigrationPending(Member $member): bool
    {
        return $member->migration_status === 'migration_pending';
    }

    /**
     * Delete migration cycle stubs for a member. When IDs are omitted, removes open/unresolved stubs only.
     */
    public function deleteStubsForMember(Member $member, ?Collection $stubs = null): int
    {
        $query = MigrationCycleStub::query()->where('member_id', $member->id);

        if ($stubs !== null) {
            $ids = $stubs
                ->filter(fn(MigrationCycleStub $stub): bool => (int) $stub->member_id === (int) $member->id)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $query->whereIn('id', $ids);
        } else {
            $query->where(function (Builder $builder): void {
                $builder->where('status', MigrationCycleStub::STATUS_UNRESOLVED)
                    ->orWhere(function (Builder $nested): void {
                        $nested->where('classification', MigrationCycleStub::CLASS_ESCALATED)
                            ->where('status', '!=', 'closed');
                    });
            });
        }

        $count = (int) $query->count();

        if ($count === 0) {
            return 0;
        }

        $query->delete();

        $this->syncMemberMigrationStateAfterStubDeletion($member);

        $this->audit->log('MIGRATION_STUBS_DELETED', 'migration', $member, $member, [
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Remove all migration stubs and clear migration enrollment so migration can begin again.
     */
    public function resetMigrationForMember(Member $member): int
    {
        $count = (int) MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->delete();

        $member->update([
            'migration_status' => null,
            'migration_cutoff_date' => null,
            'partial_clearance_granted_at' => null,
            'partial_clearance_notes' => null,
        ]);

        $this->audit->log('MIGRATION_RESET', 'migration', $member, $member, [
            'stubs_deleted' => $count,
        ]);

        return $count;
    }

    protected function syncMemberMigrationStateAfterStubDeletion(Member $member): void
    {
        $member->refresh();

        if ($member->migrationStubs()->exists()) {
            return;
        }

        $member->update([
            'migration_status' => null,
            'migration_cutoff_date' => null,
            'partial_clearance_granted_at' => null,
            'partial_clearance_notes' => null,
        ]);
    }
}
