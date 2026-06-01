<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\CollectionSummaryExportService;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

final class ContributionCycleHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function make(): array
    {
        return [
            self::sendDueNotifications(),
            self::exportCollectionSummary(),
            self::runContributionCycle(),
        ];
    }

    public static function sendDueNotifications(): Action
    {
        return Action::make('sendDueNotifications')
            ->label(__('Send due notifications'))
            ->icon('heroicon-o-bell')
            ->color('warning')
            ->schema(self::periodFormSchema())
            ->fillForm(fn (): array => self::defaultPeriod())
            ->action(function (array $data): void {
                [$month, $year] = self::resolvePeriodFromForm($data);
                $cycles = app(ContributionCycleService::class);
                $count = $cycles->sendDueNotifications($month, $year);

                Notification::make()
                    ->title(__('Notifications sent'))
                    ->body(__(':count member(s) notified for :period', [
                        'count' => $count,
                        'period' => $cycles->periodLabel($month, $year),
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function exportCollectionSummary(): Action
    {
        return Action::make('exportCollectionSummary')
            ->label(__('Export collection summary'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->schema(self::periodFormSchema())
            ->fillForm(fn (): array => self::defaultPeriod())
            ->action(function (array $data): mixed {
                [$month, $year] = self::resolvePeriodFromForm($data);

                return app(CollectionSummaryExportService::class)->downloadCsv($month, $year);
            });
    }

    public static function runContributionCycle(): Action
    {
        return Action::make('runContributionCycle')
            ->label(__('Run contribution cycle'))
            ->icon('heroicon-o-play')
            ->color('primary')
            ->schema(self::periodFormSchema())
            ->fillForm(fn (): array => self::defaultPeriod())
            ->action(function (array $data): void {
                $service = app(ContributionCycleService::class);
                [$month, $year] = self::resolvePeriodFromForm($data);
                $results = $service->applyContributions($month, $year);

                Notification::make()
                    ->title(__('Cycle complete – :period', ['period' => $service->periodLabel($month, $year)]))
                    ->body(__('Applied: :applied | Insufficient: :insufficient | Skipped: :skipped', [
                        'applied' => $results['applied']->count(),
                        'insufficient' => $results['insufficient']->count(),
                        'skipped' => $results['skipped']->count(),
                    ]))
                    ->color($results['insufficient']->count() > 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    /**
     * @return list<Component>
     */
    public static function periodFormSchema(): array
    {
        $cycles = app(ContributionCycleService::class);
        $options = $cycles->contributionCycleSelectOptionsForBulk();

        return [
            Select::make('cycle')
                ->label(__('Period'))
                ->options($options)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, callable $set) use ($cycles): void {
                    if (! is_string($state)) {
                        return;
                    }
                    [$m, $y] = $cycles->parseContributionCycleKey($state);
                    $set('month', $m);
                    $set('year', $y);
                }),
            Hidden::make('month'),
            Hidden::make('year'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPeriod(): array
    {
        $cycles = app(ContributionCycleService::class);
        [$m, $y] = $cycles->currentOpenPeriod();
        $key = $cycles->contributionCycleKey($m, $y);

        return ['cycle' => $key, 'month' => $m, 'year' => $y];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    public static function resolvePeriodFromForm(array $data): array
    {
        if (! empty($data['month']) && ! empty($data['year'])) {
            return [(int) $data['month'], (int) $data['year']];
        }

        if (! empty($data['cycle'])) {
            return app(ContributionCycleService::class)->parseContributionCycleKey((string) $data['cycle']);
        }

        return app(ContributionCycleService::class)->currentOpenPeriod();
    }
}
