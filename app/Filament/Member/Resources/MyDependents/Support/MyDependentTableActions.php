<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Filament\Support\DependentAllocationFilamentActions;
use App\Filament\Support\MemberContributionFilamentActions;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\Member;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class MyDependentTableActions
{
    /**
     * @return list<Action>
     */
    public static function headerActions(): array
    {
        return [];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function recordActions(): array
    {
        $parentResolver = fn (): ?Member => CurrentMember::get();

        return TableRecordActionGroups::wrap([
            Action::make('openDependentPortal')
                ->label(__('Open portal'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(function (Member $record): string {
                    return route('tenant.member.dependents.impersonate', ['dependent' => $record]);
                })
                ->visible(fn (Member $record): bool => ! in_array($record->status, Member::PORTAL_BLOCKED_STATUSES, true)),
            MemberContributionFilamentActions::requestOpenCycleAmountForDependentRow(),
            ...DependentAllocationFilamentActions::forRow($parentResolver),
        ]);
    }
}
