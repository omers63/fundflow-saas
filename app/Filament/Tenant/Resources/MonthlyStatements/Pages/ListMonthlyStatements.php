<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Pages;

use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Services\MonthlyStatementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Component;

class ListMonthlyStatements extends ListRecords
{
    protected static string $resource = MonthlyStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateAndNotify')
                ->label(__('Generate & notify'))
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->schema([
                    TextInput::make('period')
                        ->label(__('Period (YYYY-MM)'))
                        ->default(now()->subMonthNoOverflow()->format('Y-m'))
                        ->required(),
                    Checkbox::make('notify')
                        ->label(__('Send notifications'))
                        ->default(true),
                ])
                ->action(function (array $data, Component $livewire): void {
                    $count = app(MonthlyStatementService::class)
                        ->generateForAllMembers($data['period'], (bool) ($data['notify'] ?? false));

                    Notification::make()
                        ->title(__(':count statement(s) generated', ['count' => $count]))
                        ->success()
                        ->send();

                    MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonthlyStatementInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Generate monthly member statements, track delivery, and review period coverage.');
    }
}
