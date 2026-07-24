<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Concerns\EmbedsAsAuditWorkspacePanel;
use App\Filament\Tenant\Concerns\InteractsWithAdvancedUi;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Services\DatabaseMaintenanceService;
use App\Support\MemberPortalMaintenance;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemMaintenancePage extends Page
{
    use EmbedsAsAuditWorkspacePanel;
    use InteractsWithAdvancedUi;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'System maintenance';

    protected static ?string $slug = 'system-maintenance';

    protected static ?int $navigationSort = TenantNavigation::SORT_SYSTEM_MAINTENANCE;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected string $view = 'filament.tenant.pages.system-maintenance';

    protected string $embeddedView = 'filament.tenant.pages.embedded.system-maintenance';

    /** @var list<string> */
    public array $purgeableTables = [];

    /** @var list<string> */
    public array $alwaysExcludedTables = [];

    /** @var list<string> */
    public array $softDeleteSkippedTables = [];

    public bool $memberPortalMaintenanceEnabled = false;

    public ?string $memberPortalMaintenanceMessage = null;

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(DatabaseMaintenanceService $service, bool $embedded = false): void
    {
        $this->mountEmbedded($embedded);
        $this->redirectToAuditWorkspaceUnlessEmbedded('maintenance');
        $this->mountAdvancedUi();
        $this->refreshTableLists($service);
        $this->memberPortalMaintenanceEnabled = MemberPortalMaintenance::isEnabled();
        $this->memberPortalMaintenanceMessage = MemberPortalMaintenance::storedMessage();
    }

    public function updatedMemberPortalMaintenanceEnabled(bool $value): void
    {
        if ($value) {
            MemberPortalMaintenance::enable($this->memberPortalMaintenanceMessage);
            $this->memberPortalMaintenanceMessage = MemberPortalMaintenance::storedMessage();

            Notification::make()
                ->title(__('Member portal maintenance enabled'))
                ->body(__('Members cannot sign in and active sessions will be ended on their next request.'))
                ->warning()
                ->send();

            return;
        }

        MemberPortalMaintenance::disable();
        $this->memberPortalMaintenanceMessage = null;

        Notification::make()
            ->title(__('Member portal maintenance disabled'))
            ->body(__('Member sign-in is available again.'))
            ->success()
            ->send();
    }

    public function saveMemberPortalMaintenanceMessage(): void
    {
        if (! $this->memberPortalMaintenanceEnabled) {
            return;
        }

        MemberPortalMaintenance::updateMessage($this->memberPortalMaintenanceMessage);
        $this->memberPortalMaintenanceMessage = MemberPortalMaintenance::storedMessage();

        Notification::make()
            ->title(__('Maintenance message updated'))
            ->success()
            ->send();
    }

    public function getTitle(): string
    {
        return __('System maintenance');
    }

    public function getSubheading(): ?string
    {
        return __('Download or save a copy of your fund data.');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-system-maintenance'];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function workspacePanelActions(): array
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

                    $this->redirect($this->embedded
                        ? $this->embeddedWorkspaceUrl('maintenance')
                        : static::getUrl());
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
                ->visible(fn (): bool => $this->advancedUi)
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
