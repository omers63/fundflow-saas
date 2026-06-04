<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Pages;

use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use Filament\Resources\Pages\ListRecords;

class ListLoanEligibilityOverrideRequests extends ListRecords
{
    protected static string $resource = LoanEligibilityOverrideRequestResource::class;

    public function mount(): void
    {
        $filters = request()->input('tableFilters', []);

        if (! is_array($filters)) {
            $filters = [];
        }

        $this->redirect(LoanResource::listUrl('eligibility_reviews', $filters));
    }
}
