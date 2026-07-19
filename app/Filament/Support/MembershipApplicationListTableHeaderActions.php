<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\User;
use App\Services\MembershipApplicationImportService;
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

final class MembershipApplicationListTableHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::importApplicationsAction(),
            CreateAction::make()
                ->label(__('New Application'))
                ->icon('heroicon-o-plus-circle')
                ->url(MembershipApplicationResource::getUrl('create'))
                ->visible(fn (): bool => MembershipApplicationResource::canCreate()),
        ];
    }

    public static function importApplicationsAction(): Action
    {
        return Action::make('importApplications')
            ->label(__('Import Applications'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->visible(fn (): bool => MembershipApplicationResource::canCreate())
            ->modalHeading(__('Import applications from CSV'))
            ->modalDescription(fn (): HtmlString => new HtmlString(
                view('filament.tenant.membership-application-import-csv-help')->render()
            ))
            ->modalWidth('lg')
            ->schema([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->disk('local')
                    ->directory('membership-application-imports')
                    ->maxFiles(1)
                    ->helperText(__('Upload comma-separated data (typical .csv). If parsing fails, the importer will show detailed row errors.'))
                    ->required(),
                TextInput::make('default_password')
                    ->label(__('Default password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->helperText(__('Used when the password column is empty or shorter than 8 characters. Applicants should change it after first login.')),
                DatePicker::make('arrears_cutoff_date')
                    ->label(__('Cut-off date'))
                    ->required()
                    ->maxDate(BusinessDay::now())
                    ->native(false)
                    ->helperText(__('Contribution cycles before this date are not treated as arrears on approval. Optional CSV columns post cut-off cash and fund balances to master and member accounts.')),
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
                        self::sendImportNotification(fn (Notification $notification): Notification => $notification
                            ->title(__('Import failed'))
                            ->body(__('No readable CSV file was found. Re-upload the file, wait until it finishes uploading, then submit.'))
                            ->danger()
                            ->persistent());

                        return;
                    }

                    $fullPath = $resolved['absolutePath'];
                    $deleteRelative = $resolved['relativePathForDeletion'];

                    try {
                        $cutoffDate = filled($data['arrears_cutoff_date'] ?? null)
                            ? (string) $data['arrears_cutoff_date']
                            : (string) ($mountedData['arrears_cutoff_date'] ?? '');

                        $result = app(MembershipApplicationImportService::class)->import(
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

                    $livewire->resetTable();
                    MembershipApplicationResource::dispatchInsightsRefresh($livewire);

                    self::sendImportNotification(function (Notification $notification) use ($result): Notification {
                        $body = __('Created: :created · Skipped: :skipped · Failed: :failed', [
                            'created' => $result['created'],
                            'skipped' => $result['skipped'],
                            'failed' => $result['failed'],
                        ]);

                        if ($result['errors'] !== []) {
                            $previewLines = array_slice($result['errors'], 0, 6);
                            $preview = implode("\n", $previewLines);
                            if (count($result['errors']) > 6) {
                                $preview .= "\n… ".__('and :count more (see storage/logs/laravel.log)', [
                                    'count' => count($result['errors']) - 6,
                                ]);
                            }
                            $body .= "\n\n".$preview;
                        }

                        return $notification
                            ->title(__('Application import finished'))
                            ->body(nl2br(e($body)))
                            ->color($result['failed'] > 0 || $result['errors'] !== [] ? 'warning' : 'success')
                            ->persistent();
                    });
                } catch (\Throwable $e) {
                    report($e);

                    self::sendImportNotification(function (Notification $notification) use ($e): Notification {
                        return $notification
                            ->title(__('Import failed'))
                            ->body($e->getMessage() !== '' ? $e->getMessage() : __('An unexpected error occurred during import.'))
                            ->danger()
                            ->persistent();
                    });
                }
            });
    }

    /**
     * @param  callable(Notification): Notification  $configure
     */
    private static function sendImportNotification(callable $configure): void
    {
        $toast = Notification::make();
        $configure($toast);
        $toast->send();

        $user = auth('tenant')->user();
        if ($user instanceof User) {
            RecipientDatabaseNotification::send($user, $configure);
        }
    }
}
