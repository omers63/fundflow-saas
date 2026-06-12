<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Services\MemberExportService;
use App\Services\MemberImportService;
use App\Support\BusinessDay;
use App\Support\FilamentStoredUploadPath;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class MemberListTableHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::importMembersAction(),
            self::exportMembersAction(),
            CreateAction::make()
                ->label(__('New member'))
                ->icon('heroicon-o-plus-circle')
                ->url(MemberResource::getUrl('create'))
                ->visible(fn (): bool => MemberResource::canCreate()),
        ];
    }

    public static function importMembersAction(): Action
    {
        return Action::make('importMembers')
            ->label(__('Import members'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->visible(fn (): bool => MemberResource::canCreate())
            ->modalHeading(__('Import members from CSV'))
            ->modalDescription(fn (): HtmlString => new HtmlString(
                view('filament.tenant.member-import-csv-help')->render()
            ))
            ->modalWidth('2xl')
            ->schema([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->disk('local')
                    ->directory('member-imports')
                    ->maxFiles(1)
                    ->helperText(__('Upload comma-separated data (.csv). Row errors are reported after processing.'))
                    ->required(),
                TextInput::make('default_password')
                    ->label(__('Default password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->helperText(__('Used when the password column is empty or shorter than 8 characters. Members should change it after first login.')),
                DatePicker::make('arrears_cutoff_date')
                    ->label(__('Cut-off date'))
                    ->maxDate(BusinessDay::now())
                    ->native(false)
                    ->helperText(__('Default for all rows when the CSV does not specify contribution_arrears_cutoff_date. Required when posting cut-off cash or fund balances.')),
            ])
            ->action(function (array $data, Component $livewire): void {
                $mounted = collect($livewire->mountedActions ?? [])->last();
                $mountedData = is_array($mounted) ? ($mounted['data'] ?? []) : [];

                $csvRaw = $data['csv_file'] ?? $mountedData['csv_file'] ?? null;
                $defaultPassword = (string) (filled($data['default_password'] ?? null)
                    ? $data['default_password']
                    : ($mountedData['default_password'] ?? ''));

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
                        $cutoffDate = filled($data['arrears_cutoff_date'] ?? null)
                            ? (string) $data['arrears_cutoff_date']
                            : (string) ($mountedData['arrears_cutoff_date'] ?? '');

                        $result = app(MemberImportService::class)->import(
                            $fullPath,
                            $defaultPassword,
                            $cutoffDate !== '' ? $cutoffDate : null,
                        );
                    } finally {
                        if ($deleteRelative !== null) {
                            try {
                                Storage::disk('local')->delete($deleteRelative);
                            } catch (\Throwable) {
                            }
                        }
                    }

                    $body = __('Created: :created · Skipped: :skipped · Failed: :failed', [
                        'created' => $result['created'],
                        'skipped' => $result['skipped'],
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
                    MemberResource::dispatchInsightsRefresh($livewire);

                    Notification::make()
                        ->title(__('Member import finished'))
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

    public static function exportMembersAction(): Action
    {
        return Action::make('exportMembers')
            ->label(__('Export members'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('warning')
            ->action(fn (): mixed => app(MemberExportService::class)->downloadCsv());
    }
}
