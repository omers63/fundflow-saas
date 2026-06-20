<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Pages;

use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Filament\Tenant\Resources\SmsImportTemplates\Tables\SmsImportTemplatesTable;
use Filament\Resources\Pages\ListRecords;

class ListSmsImportTemplates extends ListRecords
{
    protected static string $resource = SmsImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SmsImportTemplatesTable::createAction(),
        ];
    }
}
