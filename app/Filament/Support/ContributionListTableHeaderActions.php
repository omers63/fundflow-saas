<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Services\ContributionCycleService;
use App\Services\ContributionExportService;
use App\Services\ContributionImportService;
use App\Services\ContributionService;
use App\Support\FilamentStoredUploadPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class ContributionListTableHeaderActions
{
    /**
     * @return list<Action|ActionGroup>
     */
    public static function contributions(): array
    {
        return [
            self::importContributionsAction(),
            self::exportContributionsAction(),
            CreateAction::make()
                ->label(__('New contribution'))
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function collect(): array
    {
        return [];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function arrears(): array
    {
        return [
            self::delinquencyToolsGroup('danger'),
        ];
    }

    public static function importContributionsAction(): Action
    {
        return Action::make('importContributions')
            ->label(__('Import contributions'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->modalHeading(__('Import contributions from CSV'))
            ->modalDescription(fn (): HtmlString => new HtmlString(
                view('filament.tenant.contribution-import-csv-help')->render()
            ))
            ->modalWidth('2xl')
            ->schema([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->disk('local')
                    ->directory('contribution-imports')
                    ->maxFiles(1)
                    ->helperText(__('Upload comma-separated data (.csv). Row errors are reported after processing.'))
                    ->required(),
            ])
            ->action(function (array $data, Component $livewire): void {
                $mounted = collect($livewire->mountedActions ?? [])->last();
                $mountedData = is_array($mounted) ? ($mounted['data'] ?? []) : [];
                $csvRaw = $data['csv_file'] ?? $mountedData['csv_file'] ?? null;

                try {
                    $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath($csvRaw);

                    if ($resolved === null) {
                        Notification::make()
                            ->title(__('Import failed'))
                            ->body(__('No readable CSV file was found. Re-upload the file, wait until it finishes uploading, then submit.'))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $fullPath = $resolved['absolutePath'];
                    $deleteRelative = $resolved['relativePathForDeletion'];

                    try {
                        $result = app(ContributionImportService::class)->import($fullPath);
                    } finally {
                        if ($deleteRelative !== null) {
                            try {
                                Storage::disk('local')->delete($deleteRelative);
                            } catch (\Throwable) {
                            }
                        }
                    }

                    $body = __('Created: :created · Failed: :failed', [
                        'created' => $result['created'],
                        'failed' => $result['failed'],
                    ]);

                    if ($result['errors'] !== []) {
                        $previewLines = array_slice($result['errors'], 0, 8);
                        $preview = implode("\n", $previewLines);
                        if (count($result['errors']) > 8) {
                            $preview .= "\n… ".__('and :count more (see storage/logs/laravel.log)', [
                                'count' => count($result['errors']) - 8,
                            ]);
                        }
                        $body .= "\n\n".$preview;
                    }

                    $livewire->resetTable();
                    ContributionResource::dispatchInsightsRefresh($livewire);

                    Notification::make()
                        ->title(__('Contribution import finished'))
                        ->body(nl2br(e($body)))
                        ->color($result['failed'] > 0 || $result['errors'] !== [] ? 'warning' : 'success')
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(__('Import failed'))
                        ->body($e->getMessage() !== '' ? $e->getMessage() : __('An unexpected error occurred during import.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function exportContributionsAction(): Action
    {
        return Action::make('exportContributions')
            ->label(__('Export contributions'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->action(fn (): mixed => app(ContributionExportService::class)->downloadCsv());
    }

    public static function cycleCollectionGroup(string $color = 'primary'): ActionGroup
    {
        return ActionGroup::make([
            ...ContributionCycleHeaderActions::make(),
            self::generatePendingAction(),
        ])
            ->label(__('Cycle collection'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color($color)
            ->button();
    }

    public static function delinquencyToolsGroup(string $color = 'warning'): ActionGroup
    {
        return ActionGroup::make(LoanDelinquencyHeaderActions::make())
            ->label(__('Delinquencies'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color($color)
            ->button();
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

                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }
            });
    }
}
