<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLoan extends EditRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => $this->record->status === 'pending'),
        ];
    }
}
