<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers\Pages;

use App\Filament\Member\Resources\MyCashTransfers\MyCashTransferResource;
use App\Filament\Member\Support\MemberCashTransferFilamentActions;
use App\Filament\Support\TableHeaderIconAction;
use Filament\Resources\Pages\ListRecords;

class ListMyCashTransfers extends ListRecords
{
    protected static string $resource = MyCashTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(MemberCashTransferFilamentActions::requestTransfer()),
        ];
    }
}
