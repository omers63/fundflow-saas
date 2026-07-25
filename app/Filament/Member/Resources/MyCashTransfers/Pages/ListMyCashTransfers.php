<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers\Pages;

use App\Filament\Member\Resources\MyCashTransfers\MyCashTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMyCashTransfers extends ListRecords
{
    protected static string $resource = MyCashTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Request transfer')),
        ];
    }
}
