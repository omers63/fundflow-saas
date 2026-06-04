<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Livewire\Component;

final class ContributionListTableHeaderActions
{
    public static function cycleActionGroup(string $color = 'primary'): ActionGroup
    {
        return ActionGroup::make([
            ...ContributionCycleHeaderActions::make(),
            self::generatePendingAction(),
        ])
            ->label(__('Cycle actions'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color($color)
            ->button();
    }

    public static function delinquencyToolsGroup(): ActionGroup
    {
        return ActionGroup::make(LoanDelinquencyHeaderActions::make())
            ->label(__('Delinquencies'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->button();
    }

    /**
     * Contributions list page header (all tabs).
     *
     * @return list<Action|ActionGroup>
     */
    public static function pageHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New contribution')),
            self::cycleActionGroup('primary'),
            self::delinquencyToolsGroup(),
        ];
    }

    public static function generatePendingAction(): Action
    {
        return Action::make('generateMonthly')
            ->label(__('Generate pending'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('Generate pending rows for the open cycle: :period', [
                'period' => app(ContributionCycleService::class)->currentOpenPeriodLabel(),
            ]))
            ->action(function (ContributionService $service, Component $livewire): void {
                $count = $service->generateMonthlyContributions();

                Notification::make()
                    ->title(__(':count contribution(s) generated', ['count' => $count]))
                    ->success()
                    ->send();

                ContributionResource::dispatchInsightsRefresh($livewire);
            });
    }
}
