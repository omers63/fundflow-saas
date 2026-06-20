<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrides\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\Tables\LoanEligibilityOverridesTable;
use Filament\Resources\Pages\ListRecords;

class ListLoanEligibilityOverrides extends ListRecords
{
    use TranslatesPageNavigationLabel;

    protected static string $resource = LoanEligibilityOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LoanEligibilityOverridesTable::createAction(),
        ];
    }
}
