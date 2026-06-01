<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;
use Throwable;

final class MemberDelinquencyActions
{
    /**
     * @return list<Action>
     */
    public static function forMemberListRow(): array
    {
        return [
            self::syncDelinquency(),
            self::restoreActive(),
        ];
    }

    /**
     * @return list<BulkAction>
     */
    public static function forMemberListBulk(): array
    {
        return [
            self::syncDelinquencyBulk(),
            self::restoreActiveBulk(),
        ];
    }

    public static function syncDelinquency(): Action
    {
        return Action::make('syncMemberDelinquency')
            ->label(__('Sync delinquency status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Member $record): bool => in_array($record->status, ['active', 'delinquent'], true))
            ->action(function (Member $record, LoanDelinquencyService $delinquency, Component $livewire): void {
                $result = $delinquency->syncMemberDelinquencyStatusForMember($record);

                if ($result['marked_delinquent'] === 0 && $result['restored_active'] === 0) {
                    Notification::make()
                        ->title(__('No status change'))
                        ->body($delinquency->memberHasArrears($record->fresh())
                            ? __('Member still has arrears and remains delinquent.')
                            : __('Member has no arrears and remains active.'))
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($result['marked_delinquent'] ? __('Marked delinquent') : __('Restored to active'))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    public static function restoreActive(): Action
    {
        return Action::make('restoreMemberActive')
            ->label(__('Restore active'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Member $record): bool => $record->status === 'delinquent')
            ->schema([
                Checkbox::make('force')
                    ->label(__('Force restore (ignore outstanding arrears)'))
                    ->helperText(__('Use only when arrears are being handled outside the system.')),
            ])
            ->action(function (Member $record, array $data, LoanDelinquencyService $delinquency, Component $livewire): void {
                try {
                    $delinquency->restoreMemberActive($record, (bool) ($data['force'] ?? false));
                    Notification::make()->title(__('Member restored to active'))->success()->send();
                    self::refreshMembersList($livewire);
                } catch (Throwable $e) {
                    Notification::make()->title(__('Cannot restore'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function syncDelinquencyBulk(): BulkAction
    {
        return BulkAction::make('syncDelinquencySelected')
            ->label(__('Sync delinquency status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->action(function (Collection $records, LoanDelinquencyService $delinquency, Component $livewire): void {
                $markedDelinquent = 0;
                $restoredActive = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || ! in_array($record->status, ['active', 'delinquent'], true)) {
                        continue;
                    }

                    $result = $delinquency->syncMemberDelinquencyStatusForMember($record);
                    $markedDelinquent += $result['marked_delinquent'];
                    $restoredActive += $result['restored_active'];
                }

                Notification::make()
                    ->title(__('Delinquency sync complete'))
                    ->body(__('Marked delinquent: :delinquent · Restored active: :restored', [
                        'delinquent' => $markedDelinquent,
                        'restored' => $restoredActive,
                    ]))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    public static function restoreActiveBulk(): BulkAction
    {
        return BulkAction::make('restoreActiveSelected')
            ->label(__('Restore active'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->schema([
                Checkbox::make('force')
                    ->label(__('Force restore (ignore outstanding arrears)'))
                    ->helperText(__('Use only when arrears are being handled outside the system.')),
            ])
            ->action(function (Collection $records, array $data, LoanDelinquencyService $delinquency, Component $livewire): void {
                $force = (bool) ($data['force'] ?? false);
                $restored = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status !== 'delinquent') {
                        continue;
                    }

                    try {
                        $delinquency->restoreMemberActive($record, $force);
                        $restored++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('Restore complete'))
                    ->body(__(':restored restored · :failed could not be restored', [
                        'restored' => $restored,
                        'failed' => $failed,
                    ]))
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    private static function refreshMembersList(Component $livewire): void
    {
        MemberResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
