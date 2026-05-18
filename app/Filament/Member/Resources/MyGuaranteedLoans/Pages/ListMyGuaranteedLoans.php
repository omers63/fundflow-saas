<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyGuaranteedLoans\Pages;

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use Filament\Resources\Pages\ListRecords;

class ListMyGuaranteedLoans extends ListRecords
{
    protected static string $resource = MyGuaranteedLoanResource::class;

    public function getSubheading(): ?string
    {
        return __('Loans where you are named as guarantor.');
    }
}
