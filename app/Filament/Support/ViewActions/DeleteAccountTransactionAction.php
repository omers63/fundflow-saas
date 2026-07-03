<?php

namespace App\Filament\Support\ViewActions;

use App\Filament\Support\AccountDetailInsightsRefresh;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Support\LedgerSettings;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Auth;

final class DeleteAccountTransactionAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->authorize(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->visible(fn (): bool => LedgerSettings::showEditDelete())
            ->modalHeading(__('Delete transaction?'))
            ->modalDescription(__('This reverses the line on the account balance and removes it permanently.'))
            ->using(function (Transaction $record): void {
                app(AccountingService::class)->deleteTransaction($record);
            })
            ->successNotificationTitle(__('Transaction deleted'))
            ->after(fn (Transaction $record) => AccountDetailInsightsRefresh::dispatchLedgerChange((int) $record->account_id));
    }
}
