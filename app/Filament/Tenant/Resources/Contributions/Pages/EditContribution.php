<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Support\ContributionTableActions;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Schemas\ContributionForm;
use App\Models\Tenant\Contribution;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditContribution extends EditRecord
{
    protected static string $resource = ContributionResource::class;

    public function getTitle(): string
    {
        assert($this->record instanceof Contribution);

        return __('Contribution');
    }

    public function getHeading(): string
    {
        assert($this->record instanceof Contribution);

        return __(':member · :period', [
            'member' => $this->record->member->name,
            'period' => $this->record->period?->translatedFormat('F Y') ?? '#'.$this->record->id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        assert($this->record instanceof Contribution);

        return ContributionForm::configure($schema, $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            ContributionTableActions::post(),
            ContributionTableActions::clearLatePosting(),
            ContributionTableActions::delete(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->visible(fn (): bool => $this->record instanceof Contribution && $this->record->isEditableByAdmin());
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title(__('Contribution updated'))
            ->success();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Contribution);

        return app(ContributionService::class)->updateAdminContribution($record, $data);
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
