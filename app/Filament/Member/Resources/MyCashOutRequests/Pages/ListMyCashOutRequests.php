<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashOutRequests\Pages;

use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Filament\Member\Support\MemberWithdrawalFilamentActions;
use App\Filament\Support\TableHeaderIconAction;
use Filament\Resources\Pages\ListRecords;

class ListMyCashOutRequests extends ListRecords
{
    protected static string $resource = MyCashOutRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Request a cash withdrawal to your registered bank account after admin approval.');
    }

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(MemberWithdrawalFilamentActions::requestCashOut()),
        ];
    }
}
