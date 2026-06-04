<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Pages;

use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListLoanEligibilityOverrideRequests extends ListRecords
{
    protected static string $resource = LoanEligibilityOverrideRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Review member requests to bypass loan eligibility rules. Approved requests create standing overrides.');
    }
}
