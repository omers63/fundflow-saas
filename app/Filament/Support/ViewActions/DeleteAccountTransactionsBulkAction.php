<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class DeleteAccountTransactionsBulkAction
{
    public static function make(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->modalDescription(__('Reverses each selected line on its account balance, then deletes it.'))
            ->using(function (DeleteBulkAction $action, $records): void {
                $accounting = app(AccountingService::class);

                foreach ($records as $record) {
                    if (! $record instanceof Transaction) {
                        continue;
                    }

                    try {
                        $accounting->deleteTransaction($record);
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure(message: $exception->getMessage());
                    }
                }
            })
            ->successNotificationTitle(__('Transactions deleted'))
            ->after(function ($records): void {
                $first = collect($records)->first();

                if ($first instanceof Transaction) {
                    AccountDetailInsightsRefresh::dispatchForAccount((int) $first->account_id);
                }
            });
    }
}
