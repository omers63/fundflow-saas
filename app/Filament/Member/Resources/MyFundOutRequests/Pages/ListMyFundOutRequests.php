<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyFundOutRequests\Pages;

use App\Filament\Member\Resources\MyFundOutRequests\MyFundOutRequestResource;
use App\Filament\Member\Support\MemberWithdrawalFilamentActions;
use App\Filament\Support\TableHeaderIconAction;
use Filament\Resources\Pages\ListRecords;

class ListMyFundOutRequests extends ListRecords
{
    protected static string $resource = MyFundOutRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Request a move from your fund account into cash after admin approval.');
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(MemberWithdrawalFilamentActions::requestFundOut()),
        ];
    }
}
