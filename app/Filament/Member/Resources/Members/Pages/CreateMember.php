<?php

namespace App\Filament\Member\Resources\Members\Pages;

use App\Filament\Member\Resources\Members\MemberResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;
}
