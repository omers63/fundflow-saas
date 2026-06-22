<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Filament\Tenant\Support\ViewSmsTransactionAction;
use App\Models\Tenant\Member;
use App\Models\Tenant\SmsTransaction;
use App\Services\AccountingService;
use App\Support\SmsClearing\SmsClearingQueuePresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

final class SmsClearingQueueActions
{
    /**
     * @return array<int, ActionGroup>
     */
    public static function groupedRecordActions(): array
    {
        return [
            ActionGroup::make(self::resolveActions())
                ->label(__('Resolve'))
                ->icon('heroicon-o-check-circle'),
            ActionGroup::make([
                self::view(),
            ])
                ->label(__('Review'))
                ->icon('heroicon-o-eye'),
            ActionGroup::make(self::removeActions())
                ->label(__('Remove'))
                ->icon('heroicon-o-trash'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function resolveActions(): array
    {
        return [
            self::postToCash(),
            self::autoPost(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function removeActions(): array
    {
        return [
            self::delete(),
        ];
    }

    public static function view(): Action
    {
        return ViewSmsTransactionAction::make()
            ->modalContent(fn (SmsTransaction $record) => TenantPortalViewModal::content(
                SmsClearingQueuePresenter::modalSections($record),
            ));
    }

    public static function postToCash(): Action
    {
        $memberOptions = self::memberOptions();

        return Action::make('postToCash')
            ->label(__('Post to cash'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (SmsTransaction $record): bool => ! $record->isPosted())
            ->fillForm(fn (SmsTransaction $record): array => ['member_id' => $record->member_id])
            ->schema([
                Select::make('member_id')
                    ->label(__('Post for member'))
                    ->options($memberOptions)
                    ->searchable()
                    ->required()
                    ->helperText(__('Auto-matched from SMS template, or select manually.')),
            ])
            ->action(function (SmsTransaction $record, array $data): void {
                $member = Member::query()->findOrFail($data['member_id']);
                app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                Notification::make()
                    ->title(__('Posted to cash account'))
                    ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function autoPost(): Action
    {
        return Action::make('autoPost')
            ->label(__('Post matched member'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Posts this row using the auto-matched member.'))
            ->visible(fn (SmsTransaction $record): bool => ! $record->isPosted() && $record->member_id !== null)
            ->action(function (SmsTransaction $record): void {
                $member = Member::query()->findOrFail($record->member_id);
                app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                Notification::make()
                    ->title(__('Posted to cash account'))
                    ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('Soft-deletes this SMS import row. If it was posted to cash, matching ledger lines are reversed first.'))
            ->using(function (SmsTransaction $record): bool {
                app(AccountingService::class)->safeDeleteSmsTransaction($record);

                return true;
            });
    }

    public static function bulkAutoPost(): BulkAction
    {
        return BulkAction::make('bulkAutoPost')
            ->label(__('Auto-post matched transactions'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Posts selected rows that already have an auto-matched member. Others are skipped.'))
            ->action(function (Collection $records): void {
                $service = app(AccountingService::class);
                $posted = 0;
                $skipped = 0;

                foreach ($records as $tx) {
                    if ($tx->isPosted() || $tx->member_id === null) {
                        $skipped++;

                        continue;
                    }

                    $member = Member::query()->find($tx->member_id);

                    if ($member === null) {
                        $skipped++;

                        continue;
                    }

                    $service->postSmsTransactionToCash($tx, $member);
                    $posted++;
                }

                Notification::make()
                    ->title(__('Auto-post complete'))
                    ->body(__('Posted: :posted | Skipped: :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkPostToCash(): BulkAction
    {
        return BulkAction::make('bulkPostToCash')
            ->label(__('Bulk post to a single member'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->schema([
                Select::make('member_id')
                    ->label(__('Post all selected for member'))
                    ->options(self::memberOptions())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $member = Member::query()->findOrFail($data['member_id']);
                $service = app(AccountingService::class);
                $posted = 0;
                $skipped = 0;

                foreach ($records as $tx) {
                    if ($tx->isPosted()) {
                        $skipped++;

                        continue;
                    }

                    $service->postSmsTransactionToCash($tx, $member);
                    $posted++;
                }

                Notification::make()
                    ->title(__('Bulk post complete'))
                    ->body(__('Posted: :posted | Already posted (skipped): :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalDescription(__('Deletes selected rows; posted transactions are reversed from the ledger first.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $accounting = app(AccountingService::class);

                foreach ($records as $record) {
                    try {
                        $accounting->safeDeleteSmsTransaction($record);
                    } catch (\Throwable $e) {
                        $action->reportBulkProcessingFailure(message: $e->getMessage());
                        report($e);
                    }
                }
            });
    }

    /**
     * @return array<int|string, string>
     */
    private static function memberOptions(): array
    {
        return Member::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => trim(($member->member_number ? $member->member_number.' — ' : '').$member->name),
            ])
            ->all();
    }
}
