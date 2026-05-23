<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use Illuminate\Database\Eloquent\Builder;

class MigrationWorkflowService
{
    /**
     * @return Builder<Member>
     */
    public function pendingMembersQuery(): Builder
    {
        return Member::query()
            ->where('migration_status', 'migration_pending')
            ->orderBy('name');
    }

    /**
     * Active members not yet enrolled in migration (no status and no stubs).
     *
     * @return Builder<Member>
     */
    public function notStartedMembersQuery(): Builder
    {
        return Member::query()
            ->where('status', 'active')
            ->whereNull('migration_status')
            ->whereDoesntHave('migrationStubs')
            ->orderBy('name');
    }

    /**
     * @return Builder<MigrationCycleStub>
     */
    public function openStubsQuery(): Builder
    {
        return MigrationCycleStub::query()
            ->with('member')
            ->where(function (Builder $query): void {
                $query->where('status', MigrationCycleStub::STATUS_UNRESOLVED)
                    ->orWhere(function (Builder $nested): void {
                        $nested->where('classification', MigrationCycleStub::CLASS_ESCALATED)
                            ->where('status', '!=', 'closed');
                    });
            })
            ->orderBy('cycle_date');
    }

    public function pendingMemberCount(): int
    {
        return (int) $this->pendingMembersQuery()->count();
    }

    public function openStubCount(): int
    {
        return (int) $this->openStubsQuery()->count();
    }

    public function notStartedMemberCount(): int
    {
        return (int) $this->notStartedMembersQuery()->count();
    }

    /**
     * @return array{pending: int, stubs: int, not_started: int}
     */
    public function queueCounts(): array
    {
        return [
            'pending' => $this->pendingMemberCount(),
            'stubs' => $this->openStubCount(),
            'not_started' => $this->notStartedMemberCount(),
        ];
    }

    public function unresolvedStubCountForMember(Member $member): int
    {
        return (int) MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->unresolved()
            ->count();
    }
}
