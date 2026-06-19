<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyGuaranteedLoans\Pages;

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use Filament\Resources\Pages\ListRecords;

class ListMyGuaranteedLoans extends ListRecords
{
    protected static string $resource = MyGuaranteedLoanResource::class;

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Loans where you are named as guarantor.');
    }
}
