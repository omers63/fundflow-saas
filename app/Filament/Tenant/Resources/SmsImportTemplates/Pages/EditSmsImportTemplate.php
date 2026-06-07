<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Pages;

use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\SmsImportTemplates\SmsImportTemplateResource;
use App\Models\Tenant\SmsImportTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditSmsImportTemplate extends EditRecord
{
    protected static string $resource = SmsImportTemplateResource::class;

    public ?string $editFrom = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->editFrom ??= request()->query('from');
    }

    protected function getRedirectUrl(): ?string
    {
        if ($this->editFrom === 'settings') {
            return Settings::smsTemplatesUrl();
        }

        return parent::getRedirectUrl();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
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
