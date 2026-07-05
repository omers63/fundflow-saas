<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Services\Loans\LoanRepaymentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Livewire\Component;

final class LoanEmiCollectionHeaderActions
{
    /**
     * @return list<Action|ActionGroup>
     */
    public static function cycleCollectionGroup(string $color = 'primary'): ActionGroup
    {
        return ActionGroup::make([
            self::sendDueNotifications(),
            self::runEmiCollectionCycle(),
        ])
            ->label(__('EMI cycle'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color($color)
            ->button();
    }

    public static function sendDueNotifications(): Action
    {
        return Action::make('sendEmiDueNotifications')
            ->label(__('Send due notifications'))
            ->icon('heroicon-o-bell')
            ->color('warning')
            ->schema(ContributionCycleHeaderActions::periodFormSchema())
            ->fillForm(fn (): array => ContributionCycleHeaderActions::defaultPeriod())
            ->action(function (array $data): void {
                [$month, $year] = ContributionCycleHeaderActions::resolvePeriodFromForm($data);
                $cycles = app(ContributionCycleService::class);
                $count = app(LoanRepaymentService::class)->sendDueNotifications($month, $year);

                Notification::make()
                    ->title(__('Notifications sent'))
                    ->body(__(':count borrower(s) notified for :period', [
                        'count' => $count,
                        'period' => $cycles->periodLabel($month, $year),
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function runEmiCollectionCycle(): Action
    {
        return Action::make('runEmiCollectionCycle')
            ->label(__('Run EMI collection cycle'))
            ->icon('heroicon-o-play')
            ->color('primary')
            ->schema(ContributionCycleHeaderActions::periodFormSchema())
            ->fillForm(fn (): array => ContributionCycleHeaderActions::defaultPeriod())
            ->action(function (array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = ContributionCycleHeaderActions::resolvePeriodFromForm($data);
                $results = app(LoanEmiCollectionCatalogService::class)->applyInstallmentsForPeriod($month, $year);

                Notification::make()
                    ->title(__('EMI cycle complete – :period', ['period' => $cycles->periodLabel($month, $year)]))
                    ->body(__('Applied: :applied | Insufficient: :insufficient | Skipped: :skipped', [
                        'applied' => $results['applied']->count(),
                        'insufficient' => $results['insufficient']->count(),
                        'skipped' => $results['skipped']->count(),
                    ]))
                    ->color($results['insufficient']->count() > 0 ? 'warning' : 'success')
                    ->send();

                LoanResource::dispatchInsightsRefresh($livewire);

                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }
            });
    }
}
