<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Services\ContributionCycleService;
use App\Services\Loans\EmiCollectionSummaryExportService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Services\Loans\LoanRepaymentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Livewire\Component;

final class LoanEmiCollectionHeaderActions
{
    /**
     * Parallel to contribution Cycle collection: notify, export, collect, prepare overdue.
     */
    public static function cycleCollectionGroup(string $color = 'primary'): ActionGroup
    {
        return ActionGroup::make([
            self::sendDueNotifications(),
            self::exportCollectionSummary(),
            self::runEmiCollectionCycle(),
            self::prepareOverdueEmis(),
        ])
            ->label(__('Cycle collection'))
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
                    ->color($count > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    public static function exportCollectionSummary(): Action
    {
        return Action::make('exportEmiCollectionSummary')
            ->label(__('Export collection summary'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->schema(ContributionCycleHeaderActions::periodFormSchema())
            ->fillForm(fn (): array => ContributionCycleHeaderActions::defaultPeriod())
            ->action(function (array $data): mixed {
                [$month, $year] = ContributionCycleHeaderActions::resolvePeriodFromForm($data);

                return app(EmiCollectionSummaryExportService::class)->downloadCsv($month, $year);
            });
    }

    public static function runEmiCollectionCycle(): Action
    {
        return Action::make('runEmiCollectionCycle')
            ->label(__('Run EMI collection cycle'))
            ->icon('heroicon-o-play')
            ->color('primary')
            ->schema([
                ...ContributionCycleHeaderActions::periodFormSchema(),
                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
            ])
            ->fillForm(fn (): array => [
                ...ContributionCycleHeaderActions::defaultPeriod(),
                'collect_oldest_arrears_first' => true,
            ])
            ->action(function (array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = ContributionCycleHeaderActions::resolvePeriodFromForm($data);
                $results = app(LoanEmiCollectionCatalogService::class)->applyInstallmentsForPeriod(
                    $month,
                    $year,
                    (bool) ($data['collect_oldest_arrears_first'] ?? true),
                );

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

    /**
     * EMI parallel to contribution "Generate pending": mark past-deadline installments overdue
     * so the To collect workspace reflects current arrears.
     */
    public static function prepareOverdueEmis(): Action
    {
        return Action::make('prepareOverdueEmis')
            ->label(__('Prepare overdue EMIs'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Prepare overdue EMIs'))
            ->modalDescription(__('Marks pending installments past their cycle deadline as overdue and refreshes late fees so they appear in EMI collection.'))
            ->action(function (LoanDelinquencyService $delinquency, Component $livewire): void {
                $count = $delinquency->markOverdueInstallments();

                Notification::make()
                    ->title(__('Overdue EMIs prepared'))
                    ->body(__(':count installment(s) marked overdue.', ['count' => $count]))
                    ->success()
                    ->send();

                LoanResource::dispatchInsightsRefresh($livewire);

                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }
            });
    }
}
