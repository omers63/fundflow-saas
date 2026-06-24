<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Pages;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Widgets\MembershipApplicationInsightsWidget;
use App\Models\Tenant\MembershipApplication;
use App\Services\MembershipApplicationImportService;
use App\Support\BusinessDay;
use App\Support\FilamentStoredUploadPath;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class ListMembershipApplications extends ListRecords
{
    protected static string $resource = MembershipApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importApplications')
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
                            $this->sendImportNotification(
                                Notification::make()
                                    ->title(__('Import failed'))
                                    ->body(__('No readable CSV file was found. Re-upload the file, wait until it finishes uploading, then submit.'))
                                    ->danger()
                                    ->persistent()
                            );

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

                        $livewire->resetTable();
                        MembershipApplicationResource::dispatchInsightsRefresh($livewire);

                        $this->sendImportNotification(
                            Notification::make()
                                ->title(__('Application import finished'))
                                ->body(nl2br(e($body)))
                                ->color($result['failed'] > 0 || $result['errors'] !== [] ? 'warning' : 'success')
                                ->persistent()
                        );
                    } catch (\Throwable $e) {
                        report($e);

                        $this->sendImportNotification(
                            Notification::make()
                                ->title(__('Import failed'))
                                ->body($e->getMessage() !== '' ? $e->getMessage() : __('An unexpected error occurred during import.'))
                                ->danger()
                                ->persistent()
                        );
                    }
                }),
            CreateAction::make()
                ->label(__('New Application'))
                ->icon('heroicon-o-plus-circle')
                ->url(MembershipApplicationResource::getUrl('create'))
                ->visible(fn (): bool => MembershipApplicationResource::canCreate()),
        ];
    }

    public function getTabs(): array
    {
        $pendingCount = MembershipApplication::pending()->count();
        $approvedCount = MembershipApplication::query()->where('status', 'approved')->count();
        $rejectedCount = MembershipApplication::query()->where('status', 'rejected')->count();

        return [
            'all' => Tab::make(MembershipApplicationResource::listTabLabel('all')),
            'pending' => Tab::make(MembershipApplicationResource::listTabLabel('pending'))
                ->badge($pendingCount > 0 ? (string) $pendingCount : null)
                ->badgeColor('warning'),
            'approved' => Tab::make(MembershipApplicationResource::listTabLabel('approved'))
                ->badge($approvedCount > 0 ? (string) $approvedCount : null)
                ->badgeColor('success'),
            'rejected' => Tab::make(MembershipApplicationResource::listTabLabel('rejected'))
                ->badge($rejectedCount > 0 ? (string) $rejectedCount : null)
                ->badgeColor('danger'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $tab = MembershipApplicationResource::resolveListTab();

        return match ($tab) {
            'pending' => $query->where('status', 'pending'),
            'approved' => $query->where('status', 'approved'),
            'rejected' => $query->where('status', 'rejected'),
            default => $query,
        };
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MembershipApplicationInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return match (MembershipApplicationResource::resolveListTab()) {
            'pending' => __('Applications awaiting document check, fee confirmation, and approval.'),
            'approved' => __('Approved applications — members were created on acceptance.'),
            'rejected' => __('Rejected applications kept for audit.'),
            default => __('Review new membership applications and manage the onboarding pipeline.'),
        };
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ff-tenant-applications-workspace',
        ];
    }

    private function sendImportNotification(Notification $notification): void
    {
        $notification->send();

        $user = auth('tenant')->user();
        if ($user !== null) {
            $notification->sendToDatabase($user);
        }
    }
}
