<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Services\AccountingService;
use Filament\Resources\Pages\CreateRecord;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    protected function afterCreate(): void
    {
        app(AccountingService::class)->createMemberAccounts($this->record);

        MemberResource::dispatchInsightsRefresh($this);
    }
}
