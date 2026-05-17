<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Component;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contributionCycles')
                ->label(__('Contribution cycles'))
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->url(ContributionCyclePage::getUrl()),
            CreateAction::make(),
            Action::make('generateMonthly')
                ->label(__('Generate pending'))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('Generate pending rows for the open cycle: :period', [
                    'period' => app(ContributionCycleService::class)->currentOpenPeriodLabel(),
                ]))
                ->action(function (ContributionService $service, Component $livewire) {
                    $count = $service->generateMonthlyContributions();
                    Notification::make()
                        ->title(__(':count contribution(s) generated', ['count' => $count]))
                        ->success()
                        ->send();

                    ContributionResource::dispatchInsightsRefresh($livewire);
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ContributionInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Track monthly contributions, posting status, open-cycle collection, and cycle workflows.');
    }
}
