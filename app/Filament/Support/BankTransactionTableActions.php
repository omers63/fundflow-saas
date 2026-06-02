<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\FundFlowService;
use App\Support\BankTransactionDeletion;
use App\Support\BankTransactionWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class BankTransactionTableActions
{
    public static function postToMember(): Action
    {
        return Action::make('postToMember')
            ->label(__('Post to member'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $record): bool => BankTransactionWorkflow::canPostToMember($record))
            ->modalDescription(fn (BankTransaction $record): string => $record->status === 'imported'
                ? __('Posts this line to the master cash pool, then credits or debits the member cash account.')
                : __('Credits or debits the member cash account for this statement line.'))
            ->form([
                Select::make('member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::active()->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (BankTransaction $record, array $data, FundFlowService $service): void {
                $member = Member::findOrFail($data['member_id']);
                $service->ensureMirroredAndPostToMember($record, $member);

                Notification::make()
                    ->title(__('Posted to :name', ['name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function postToMemberBulk(): BulkAction
    {
        return BulkAction::make('postSelectedToMember')
            ->label(__('Post to member'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Post selected statement lines to the same member. Imported lines are posted to master cash first.'))
            ->form([
                Select::make('member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::active()->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (BulkAction $action, Collection $records, array $data, FundFlowService $service): void {
                $member = Member::findOrFail($data['member_id']);
                $count = 0;

                foreach ($records as $record) {
                    if (! $record instanceof BankTransaction) {
                        continue;
                    }

                    if (! BankTransactionWorkflow::canPostToMember($record)) {
                        continue;
                    }

                    try {
                        $service->ensureMirroredAndPostToMember($record, $member);
                        $count++;
                    } catch (Throwable $exception) {
                        $label = filled($record->description)
                            ? $record->description
                            : '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $label.': '.$exception->getMessage(),
                        );
                    }
                }

                Notification::make()
                    ->title(__(':count transaction(s) posted to :name', ['count' => $count, 'name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (BankTransaction $record): bool => BankTransactionDeletion::canDelete($record))
            ->modalHeading(__('Delete statement line'))
            ->modalDescription(fn (BankTransaction $record): string => BankTransactionDeletion::modalDescription($record))
            ->using(function (BankTransaction $record): void {
                app(BankTransactionDeletion::class)->delete($record);
            });
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalHeading(__('Delete statement lines'))
            ->modalDescription(__('Permanently removes the selected import lines. Lines linked to fund postings, cash-outs, or membership fees are skipped. Mirrored or posted lines also remove their linked ledger entries.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $deletion = app(BankTransactionDeletion::class);

                foreach ($records as $record) {
                    if (! $record instanceof BankTransaction) {
                        continue;
                    }

                    try {
                        $deletion->delete($record);
                    } catch (Throwable $exception) {
                        $label = filled($record->description)
                            ? $record->description
                            : '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $label.': '.$exception->getMessage(),
                        );
                    }
                }
            });
    }
}
