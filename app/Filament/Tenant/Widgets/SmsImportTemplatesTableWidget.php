<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\SmsImportTemplates\Pages\EditSmsImportTemplate;
use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Filament\Tenant\Resources\SmsImportTemplates\Tables\SmsImportTemplatesTable;
use App\Models\Tenant\SmsImportTemplate;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SmsImportTemplatesTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return SmsImportTemplateResource::getEloquentQuery();
    }

    public function table(Table $table): Table
    {
        return SmsImportTemplatesTable::configure($table)
            ->recordUrl(fn(SmsImportTemplate $record): string => EditSmsImportTemplate::getUrl(['record' => $record]));
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->paginationMode(PaginationMode::Default);
    }
}
