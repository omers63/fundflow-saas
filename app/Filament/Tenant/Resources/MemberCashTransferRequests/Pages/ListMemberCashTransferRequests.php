<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages;

use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListMemberCashTransferRequests extends ListRecords
{
    protected static string $resource = MemberCashTransferRequestResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;
}
