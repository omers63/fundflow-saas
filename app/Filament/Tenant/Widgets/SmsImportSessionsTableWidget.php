<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\SmsImportSessions\SmsImportSessionResource;
use App\Filament\Tenant\Resources\SmsImportSessions\Tables\SmsImportSessionsTable;
use App\Models\Tenant\SmsImportSession;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SmsImportSessionsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return SmsImportSessionResource::getEloquentQuery()->with(['template', 'importer']);
    }

    public function table(Table $table): Table
    {
        return SmsImportSessionsTable::configure($table, embedInBankWorkspace: true)
            ->recordUrl(fn (SmsImportSession $record): string => SmsImportSessionResource::getUrl('view', ['record' => $record]));
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
