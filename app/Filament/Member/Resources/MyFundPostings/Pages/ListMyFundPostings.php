<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Support\MemberDepositFilamentActions;
use Filament\Resources\Pages\ListRecords;

class ListMyFundPostings extends ListRecords
{
    protected static string $resource = MyFundPostingResource::class;

    public function getSubheading(): ?string
    {
        return __('Submit deposits and track review status.');
    }

    protected function getHeaderActions(): array
    {
        return [
            MemberDepositFilamentActions::requestDeposit(),
        ];
    }
}
