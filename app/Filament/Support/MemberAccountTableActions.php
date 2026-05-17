<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Models\Tenant\Account;
use App\Support\MemberAccountDeletion;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Throwable;

final class MemberAccountTableActions
{
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (Account $record): bool => MemberAccountDeletion::canDelete($record))
            ->modalDescription(fn (Account $record): string => MemberAccountDeletion::modalDescription($record))
            ->before(fn (Account $record): void => MemberAccountDeletion::ensureCanDelete($record))
            ->after(fn (Component $livewire): mixed => AccountResource::dispatchInsightsRefresh($livewire));
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalDescription(__('Only member accounts with a zero balance can be deleted. Ledger transactions on deleted accounts are removed permanently.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                foreach ($records as $record) {
                    if (! $record instanceof Account) {
                        continue;
                    }

                    try {
                        MemberAccountDeletion::ensureCanDelete($record);
                        $record->delete();
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure(
                            message: $record->name.': '.$exception->getMessage(),
                        );
                    }
                }
            })
            ->after(fn (Component $livewire): mixed => AccountResource::dispatchInsightsRefresh($livewire));
    }
}
