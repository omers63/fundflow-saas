<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\DatabaseBackupOverviewWidget;
use App\Filament\Tenant\Widgets\DatabaseBackupsTableWidget;
use App\Services\DatabaseMaintenanceService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class SystemMaintenancePage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'System maintenance';

    protected static ?string $slug = 'system-maintenance';

    protected static ?int $navigationSort = TenantNavigation::SORT_SYSTEM_MAINTENANCE;

    protected string $view = 'filament.tenant.pages.system-maintenance';

    /** @var list<string> */
    public array $purgeableTables = [];

    /** @var list<string> */
    public array $alwaysExcludedTables = [];

    /** @var list<string> */
    public array $softDeleteSkippedTables = [];

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Schema::hasTable('database_backups');
    }

    public function mount(DatabaseMaintenanceService $service): void
    {
        $this->refreshTableLists($service);
    }

    public function getTitle(): string
    {
        return __('System maintenance');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DatabaseBackupOverviewWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            DatabaseBackupsTableWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveToServer')
                ->label(__('Save backup to server'))
                ->icon(Heroicon::OutlinedServerStack)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Save backup to server?'))
                ->modalDescription(__('Creates a new file under your tenant backup folder and adds a row to the backup history. Use Download backup for a one-off copy without storing on the server.'))
                ->action(function (): void {
                    try {
                        app(DatabaseMaintenanceService::class)->createStoredBackup();
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()
                            ->title(__('Backup failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Backup saved'))
                        ->body(__('The file was written to storage and listed below.'))
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl());
                }),
            Action::make('download')
                ->label(__('Download backup'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('primary')
                ->url(route('tenant.admin.system.backup-download')),
            Action::make('purge')
                ->label(__('Purge now'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Purge tables without soft deletes?'))
                ->modalDescription(
                    __('All rows in each listed table will be permanently removed. ').
                    __('Tables with a deleted_at column are skipped. ').
                    __('Users, permissions, sessions, queues, cache, and migrations are always preserved.')
                )
                ->schema([
                    TextInput::make('confirm')
                        ->label(__('Type PURGE to confirm'))
                        ->required()
                        ->rule('in:PURGE')
                        ->helperText(__('This action cannot be undone.')),
                ])
                ->action(function (): void {
                    $service = app(DatabaseMaintenanceService::class);
                    $count = $service->purgePurgeableTables();

                    Notification::make()
                        ->title(__('Database purged'))
                        ->body($count > 0
                            ? __('Truncated :count table(s).', ['count' => $count])
                            : __('No tables matched the purge rules.'))
                        ->success()
                        ->send();

                    $this->refreshTableLists($service);
                }),
        ];
    }

    private function refreshTableLists(DatabaseMaintenanceService $service): void
    {
        $this->purgeableTables = $service->getPurgeableTables();
        $this->alwaysExcludedTables = $service->alwaysExcludedTableNames();
        $this->softDeleteSkippedTables = $service->getTablesSkippedForSoftDeletes();
    }
}
