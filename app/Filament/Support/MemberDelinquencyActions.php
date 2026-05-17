<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Throwable;

final class MemberDelinquencyActions
{
    /**
     * @return list<Action>
     */
    public static function forMemberView(): array
    {
        return [
            self::syncDelinquency(),
            self::markDelinquent(),
            self::restoreActive(),
            self::openDelinquencyWorkspace(),
        ];
    }

    public static function syncDelinquency(): Action
    {
        return Action::make('syncMemberDelinquency')
            ->label(__('Sync delinquency status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Member $record): bool => in_array($record->status, ['active', 'delinquent'], true))
            ->action(function (Member $record, LoanDelinquencyService $delinquency): void {
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
            });
    }

    public static function markDelinquent(): Action
    {
        return Action::make('markMemberDelinquent')
            ->label(__('Mark delinquent'))
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (Member $record): bool => $record->status === 'active')
            ->requiresConfirmation()
            ->modalDescription(__('Blocks member portal access until arrears are cleared and status is restored.'))
            ->action(function (Member $record, LoanDelinquencyService $delinquency): void {
                try {
                    $delinquency->markMemberDelinquent($record);
                    Notification::make()->title(__('Member marked delinquent'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('Cannot update status'))->body($e->getMessage())->danger()->send();
                }
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
            ->action(function (Member $record, array $data, LoanDelinquencyService $delinquency): void {
                try {
                    $delinquency->restoreMemberActive($record, (bool) ($data['force'] ?? false));
                    Notification::make()->title(__('Member restored to active'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('Cannot restore'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function openDelinquencyWorkspace(): Action
    {
        return Action::make('openDelinquency')
            ->label(__('Delinquency workspace'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('gray')
            ->url(fn (): string => LoanResource::getUrl('delinquency'))
            ->openUrlInNewTab();
    }
}
