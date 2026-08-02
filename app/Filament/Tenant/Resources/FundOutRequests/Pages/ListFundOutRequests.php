<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundOutRequests\Pages;

use App\Filament\Tenant\Resources\FundOutRequests\FundOutRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListFundOutRequests extends ListRecords
{
    protected static string $resource = FundOutRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Review member fund-to-cash transfer requests. Accepting moves money from fund to cash with master mirrors.');
    }
}
