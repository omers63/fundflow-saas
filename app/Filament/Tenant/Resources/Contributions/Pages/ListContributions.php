<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('generateMonthly')
                ->label('Generate monthly')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription(__('Generate pending contributions for all active members for the current month.'))
                ->action(function (ContributionService $service) {
                    $period = now()->startOfMonth()->format('Y-m-d');
                    $count = $service->generateMonthlyContributions($period);
                    Notification::make()
                        ->title(__(':count contribution(s) generated', ['count' => $count]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
