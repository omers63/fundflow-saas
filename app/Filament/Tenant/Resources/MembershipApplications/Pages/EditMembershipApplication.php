<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Pages;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use Filament\Resources\Pages\EditRecord;

class EditMembershipApplication extends EditRecord
{
    protected static string $resource = MembershipApplicationResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }
}
