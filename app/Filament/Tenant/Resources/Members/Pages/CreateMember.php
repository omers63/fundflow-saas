<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Services\Tenant\HouseholdMemberService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $password = (string) ($this->form->getState()['portal_password'] ?? '');

        return app(HouseholdMemberService::class)->createFromAdmin($data, $password);
    }

    protected function afterCreate(): void
    {
        MemberResource::dispatchInsightsRefresh($this);
    }
}
