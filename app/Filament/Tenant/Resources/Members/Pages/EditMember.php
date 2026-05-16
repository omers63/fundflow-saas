<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use Filament\Resources\Pages\EditRecord;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return __('Member');
    }
}
