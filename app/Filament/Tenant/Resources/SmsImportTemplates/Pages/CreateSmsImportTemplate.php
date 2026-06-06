<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Pages;

use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Models\Tenant\SmsImportTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateSmsImportTemplate extends CreateRecord
{
    protected static string $resource = SmsImportTemplateResource::class;

    protected function afterCreate(): void
    {
        /** @var SmsImportTemplate $record */
        $record = $this->record;

        if (! $record->is_default) {
            return;
        }

        SmsImportTemplate::query()
            ->where('id', '!=', $record->id)
            ->when(
                filled($record->bank_name),
                fn ($query) => $query->where('bank_name', $record->bank_name),
                fn ($query) => $query->whereNull('bank_name'),
            )
            ->update(['is_default' => false]);
    }
}
