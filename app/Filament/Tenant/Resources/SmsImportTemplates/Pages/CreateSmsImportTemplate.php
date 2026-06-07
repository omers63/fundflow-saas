<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Pages;

use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Models\Tenant\SmsImportTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateSmsImportTemplate extends CreateRecord
{
    protected static string $resource = SmsImportTemplateResource::class;

    public ?string $createFrom = null;

    public function mount(): void
    {
        parent::mount();

        $this->createFrom ??= request()->query('from');
    }

    protected function getRedirectUrl(): string
    {
        if ($this->createFrom === 'settings') {
            return Settings::smsTemplatesUrl();
        }

        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

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
