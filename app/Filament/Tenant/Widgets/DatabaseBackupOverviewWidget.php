<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Services\DatabaseMaintenanceService;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;

class DatabaseBackupOverviewWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.tenant.widgets.database-backup-overview';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $stats = app(DatabaseMaintenanceService::class)->getBackupOverviewStats();

        return [
            'stats' => $stats,
            'liveSize' => $stats['size_bytes'] !== null
                ? Number::fileSize($stats['size_bytes'], precision: 2)
                : '—',
            'modified' => $stats['modified_at']?->timezone(config('app.timezone'))->format('d M Y H:i'),
            'storedTotal' => Number::fileSize($stats['stored_backups_total_bytes'], precision: 2),
            'folderTotal' => Number::fileSize($stats['backup_folder_total_bytes'], precision: 2),
            'backupFolder' => app(DatabaseMaintenanceService::class)->backupDirectoryRelative(),
        ];
    }
}
