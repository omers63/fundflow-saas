<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Services\Loans\DelinquencyDigestService;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

final class LoanDelinquencyHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function make(): array
    {
        return [
            self::runMaintenance(),
            self::markOverdueOnly(),
            self::sendDigest(),
        ];
    }

    public static function runMaintenance(): Action
    {
        return Action::make('runDelinquencyMaintenance')
            ->label(__('Run delinquency check'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Run delinquency check'))
            ->modalDescription(__('Marks overdue installments, refreshes arrears signals, and processes default warnings or guarantor debits per fund rules.'))
            ->action(function (LoanDelinquencyService $delinquency, Component $livewire): void {
                $result = $delinquency->runDailyMaintenance();

                DelinquencyWorkspacePage::rememberMaintenanceResult($result);

                Notification::make()
                    ->title(__('Delinquency check complete'))
                    ->body(__('Overdue: :overdue · Arrears: :arrears · Clear: :cleared · Warnings: :warned · Guarantor debits: :debited', [
                        'overdue' => $result['marked_overdue'],
                        'arrears' => $result['delinquent_count'],
                        'cleared' => $result['cleared_count'],
                        'warned' => $result['warned'],
                        'debited' => $result['debited_from_guarantor'],
                    ]))
                    ->success()
                    ->send();

                self::refreshLivewire($livewire);
            });
    }

    public static function markOverdueOnly(): Action
    {
        return Action::make('markOverdueInstallments')
            ->label(__('Mark overdue only'))
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (LoanDelinquencyService $delinquency, Component $livewire): void {
                $count = $delinquency->markOverdueInstallments();

                Notification::make()
                    ->title(__('Installments updated'))
                    ->body(__(':count installment(s) marked overdue.', ['count' => $count]))
                    ->success()
                    ->send();

                self::refreshLivewire($livewire);
            });
    }

    public static function sendDigest(): Action
    {
        return Action::make('sendDelinquencyDigest')
            ->label(__('Send admin digest'))
            ->icon('heroicon-o-bell-alert')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('Notifies tenant administrators of overdue installments, members in arrears, contribution arrears, policy delinquents, and guarantor exposure.'))
            ->action(function (DelinquencyDigestService $digest): void {
                $count = $digest->notifyAdminsIfNeeded();

                Notification::make()
                    ->title($count > 0 ? __('Digest sent') : __('Nothing to report'))
                    ->body($count > 0
                        ? __(':count administrator(s) notified.', ['count' => $count])
                        : __('No overdue installments, members in arrears, contribution arrears, or guarantor exposure.'))
                    ->color($count > 0 ? 'success' : 'info')
                    ->send();
            });
    }

    private static function refreshLivewire(Component $livewire): void
    {
        if ($livewire instanceof DelinquencyWorkspacePage) {
            DelinquencyWorkspacePage::refreshAfterAction($livewire);

            return;
        }

        LoanResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
