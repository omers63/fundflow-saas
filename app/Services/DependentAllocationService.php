<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\DependentAllocationChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DependentAllocationService
{
    public function __construct(
        private readonly MemberMonthlyAllocationService $monthlyAllocations,
    ) {}

    public function changeAllocation(
        Member $parent,
        Member $dependent,
        int $newAmount,
        ?string $note = null,
        ?User $changedBy = null,
    ): ?DependentAllocationChange {
        $this->assertDependentBelongsToParent($parent, $dependent);

        if (! Member::isValidDependentContributionAmount($newAmount)) {
            throw new InvalidArgumentException(__('Invalid monthly allocation amount selected.'));
        }

        $this->monthlyAllocations->assertCanChangeMonthlyContribution($parent);

        $oldAmount = (int) $dependent->monthly_contribution_amount;

        if ($oldAmount === $newAmount) {
            return null;
        }

        $change = null;

        DB::transaction(function () use ($parent, $dependent, $oldAmount, $newAmount, $note, $changedBy, &$change): void {
            Member::withoutSelfAllocationGuard(fn () => $dependent->update(['monthly_contribution_amount' => $newAmount]));

            $change = DependentAllocationChange::query()->create([
                'parent_member_id' => $parent->id,
                'dependent_member_id' => $dependent->id,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'changed_by_user_id' => $changedBy?->id ?? auth('tenant')->id(),
                'note' => $note,
            ]);
        });

        if ($change === null) {
            return null;
        }

        $this->dispatchNotifications($change);

        return $change;
    }

    /**
     * @param  array<int, int>  $updates  dependent_id => new_amount
     * @return list<array{dependent: Member, change: ?DependentAllocationChange, error: ?string}>
     */
    public function changeMultiple(
        Member $parent,
        array $updates,
        ?string $note = null,
        ?User $changedBy = null,
    ): array {
        $results = [];

        foreach ($updates as $dependentId => $newAmount) {
            $dependent = $parent->dependents()
                ->whereKey((int) $dependentId)
                ->first();

            if ($dependent === null) {
                continue;
            }

            try {
                $change = $this->changeAllocation($parent, $dependent, (int) $newAmount, $note, $changedBy);
                $results[] = [
                    'dependent' => $dependent,
                    'change' => $change,
                    'error' => null,
                ];
            } catch (\Throwable $exception) {
                Log::error('DependentAllocationService: failed for dependent #'.$dependent->id.': '.$exception->getMessage());
                $results[] = [
                    'dependent' => $dependent,
                    'change' => null,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param  list<array{dependent: Member, change: ?DependentAllocationChange, error: ?string}>  $results
     */
    public function buildSummary(array $results): string
    {
        $collection = collect($results);

        $changed = $collection->filter(fn (array $row): bool => $row['change'] !== null);
        $errors = $collection->filter(fn (array $row): bool => $row['error'] !== null);
        $same = $collection->filter(fn (array $row): bool => $row['change'] === null && $row['error'] === null);

        $parts = [];

        if ($changed->isNotEmpty()) {
            $names = $changed->map(fn (array $row): string => (string) $row['dependent']->name)->join(', ');
            $parts[] = __('Updated :count dependent(s): :names.', [
                'count' => $changed->count(),
                'names' => $names,
            ]);
        }

        if ($same->isNotEmpty()) {
            $parts[] = __(':count unchanged (same amount).', ['count' => $same->count()]);
        }

        if ($errors->isNotEmpty()) {
            $parts[] = __(':count failed: :messages', [
                'count' => $errors->count(),
                'messages' => $errors->map(fn (array $row): string => (string) $row['error'])->join('; '),
            ]);
        }

        return implode(' ', $parts) ?: __('No changes made.');
    }

    private function assertDependentBelongsToParent(Member $parent, Member $dependent): void
    {
        if ((int) $dependent->parent_member_id !== (int) $parent->id) {
            throw new InvalidArgumentException(__('This member is not your dependent.'));
        }
    }

    private function dispatchNotifications(DependentAllocationChange $change): void
    {
        $change->load(['dependent.user', 'parent.user', 'changedBy']);

        $dependentUser = $change->dependent?->user;
        if ($dependentUser !== null) {
            try {
                $dependentUser->notify(new DependentAllocationChangedNotification($change, 'dependent'));
            } catch (\Throwable $exception) {
                Log::error('DependentAllocationService: dependent notification failed: '.$exception->getMessage());
            }
        }

        $parentUser = $change->parent?->user;
        if ($parentUser !== null) {
            try {
                $parentUser->notify(new DependentAllocationChangedNotification($change, 'parent'));
            } catch (\Throwable $exception) {
                Log::error('DependentAllocationService: parent notification failed: '.$exception->getMessage());
            }
        }

        User::query()->where('is_admin', true)->each(function (User $admin) use ($change): void {
            try {
                $admin->notify(new DependentAllocationChangedNotification($change, 'admin'));
            } catch (\Throwable $exception) {
                Log::error('DependentAllocationService: admin notification failed for user #'.$admin->id.': '.$exception->getMessage());
            }
        });
    }
}
