<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\DatabaseBackup;
use App\Services\DatabaseMaintenanceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class DatabaseBackupsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return DatabaseBackup::query()->with('user')->latest();
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->heading(__('Existing backups'))
            ->description(__('Backup files stored on the server. You can download or delete individual backups.'))
            ->emptyStateHeading(__('No database backups'))
            ->emptyStateDescription(__('No backup files have been saved on the server yet.'))
            ->columns([
                TextColumn::make('filename')
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state): string => $state !== null
                        ? Number::fileSize($state, precision: 2)
                        : '—')
                    ->sortable(),
                TextColumn::make('driver')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sqlite' => 'info',
                        'mysql' => 'warning',
                        'mariadb' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Created by'))
                    ->placeholder(__('—')),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->options([
                        'sqlite' => 'SQLite',
                        'mysql' => 'MySQL',
                        'mariadb' => 'MariaDB',
                    ]),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (DatabaseBackup $record): string => route('tenant.admin.system.backup-stored-download', $record)),
                DeleteAction::make()
                    ->label(__('Delete'))
                    ->modalHeading(__('Delete backup?'))
                    ->modalDescription(__('Removes the database row and deletes the file from storage.'))
                    ->using(function (DatabaseBackup $record): bool {
                        app(DatabaseMaintenanceService::class)->deleteStoredBackup($record);

                        return true;
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc'), TableGrouping::databaseBackups());
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
