<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Filament\Support\DependentAllocationFilamentActions;
use App\Filament\Support\TableRecordActionGroups;
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
        return [
            Action::make('apply_for_dependent')
                ->label(__('Apply for a Dependent'))
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->url(fn (): string => route('tenant.membership', ['on_behalf' => 1]))
                ->openUrlInNewTab(),

            DependentAllocationFilamentActions::updateAllAllocationsHeaderAction(
                fn (): ?\App\Models\Tenant\Member => CurrentMember::get(),
            ),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function recordActions(): array
    {
        return TableRecordActionGroups::wrap(
            DependentAllocationFilamentActions::forRow(
                fn (): ?\App\Models\Tenant\Member => CurrentMember::get(),
            ),
        );
    }
}
