<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\FundFlowService;
use App\Services\PendingOperationalClearanceDeletionService;
use App\Support\BankTransactionDeletion;
use App\Support\BankTransactionWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
                MemberSelect::make('member_id')
                    ->required(),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, FundFlowService $service): void {
                $member = Member::findOrFail($data['member_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->ensureMirroredAndPostToMember($record, $member),
                        __('Could not post to member'),
                    )
                ) {
                    return;
                }

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
            ->modalDescription(__('Post statement lines to the same member. Imported lines are posted to master cash first.'))
            ->form([
                MemberSelect::make('member_id')
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

    public static function deletePendingOperationalClearance(): DeleteAction
    {
        return DeleteAction::make('deletePendingOperational')
            ->visible(fn (BankTransaction $record): bool => PendingOperationalClearanceDeletionService::canDelete($record))
            ->modalHeading(__('Remove pending bank match'))
            ->modalDescription(fn (BankTransaction $record): string => PendingOperationalClearanceDeletionService::modalDescription($record))
            ->using(function (BankTransaction $record): void {
                app(PendingOperationalClearanceDeletionService::class)->delete($record);

                Notification::make()
                    ->title(__('Pending bank match removed'))
                    ->success()
                    ->send();
            });
    }

    public static function deletePendingOperationalClearanceBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalHeading(__('Remove pending bank matches'))
            ->modalDescription(__('Removes selected uncleared deposit, cash-out, and expense lines. Accepted operations are reversed on the ledger first.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $deletion = app(PendingOperationalClearanceDeletionService::class);
                $removed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof BankTransaction) {
                        continue;
                    }

                    if (! PendingOperationalClearanceDeletionService::canDelete($record)) {
                        continue;
                    }

                    try {
                        $deletion->delete($record);
                        $removed++;
                    } catch (Throwable $exception) {
                        $label = filled($record->description)
                            ? $record->description
                            : '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $label.': '.$exception->getMessage(),
                        );
                    }
                }

                if ($removed > 0) {
                    Notification::make()
                        ->title(__(':count pending bank match line(s) removed', ['count' => $removed]))
                        ->success()
                        ->send();
                }
            });
    }
}
