<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\FundTier;
use App\Services\Loans\LoanExportService;
use App\Services\Loans\LoanImportService;
use App\Services\Loans\LoanQueueOrderingService;
use App\Support\FilamentStoredUploadPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class LoanListTableHeaderActions
{
    /**
     * @return list<Action|ActionGroup>
     */
    public static function portfolio(): array
    {
        return [
            self::importLoansAction(),
            self::exportLoansAction(),
            CreateAction::make(),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function delinquency(): array
    {
        return [
            self::delinquencyToolsGroup(),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function eligibilityReviews(): array
    {
        return [
            self::loanOverridesAction(),
        ];
    }

    /**
     * @return list<Action>
     */
    public static function queue(): array
    {
        return [
            Action::make('resequence')
                ->label(__('Resequence queues'))
                ->icon('heroicon-o-arrows-up-down')
                ->requiresConfirmation()
                ->action(function (): void {
                    foreach (FundTier::query()->where('is_active', true)->pluck('id') as $tierId) {
                        LoanQueueOrderingService::resequenceFundTier((int) $tierId);
                    }

                    Notification::make()
                        ->title(__('Queues resequenced'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public static function delinquencyToolsGroup(): ActionGroup
    {
        return ActionGroup::make(LoanDelinquencyHeaderActions::make())
            ->label(__('Delinquency tools'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('gray')
            ->button();
    }

    public static function loanOverridesAction(): Action
    {
        return Action::make('loanOverrides')
            ->label(__('Loan overrides'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->url(fn (): string => LoanEligibilityOverrideResource::getUrl('index'));
    }

    public static function importLoansAction(): Action
    {
        return Action::make('importLoans')
            ->label(__('Import loans'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->visible(fn (): bool => LoanResource::canCreate())
            ->modalHeading(__('Import loans from CSV'))
            ->modalDescription(fn (): HtmlString => new HtmlString(
                view('filament.tenant.loan-import-csv-help')->render()
            ))
            ->modalWidth('2xl')
            ->schema([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->disk('local')
                    ->directory('loan-imports')
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
                        $result = app(LoanImportService::class)->import($fullPath);
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
                    LoanResource::dispatchInsightsRefresh($livewire);

                    Notification::make()
                        ->title(__('Loan import finished'))
                        ->body(new HtmlString(nl2br(e($body))))
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

    public static function exportLoansAction(): Action
    {
        return Action::make('exportLoans')
            ->label(__('Export loans'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->visible(fn (): bool => LoanResource::canCreate())
            ->action(fn (): mixed => app(LoanExportService::class)->downloadCsv());
    }
}
