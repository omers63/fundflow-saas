<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Pages;

use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMonthlyStatement extends CreateRecord
{
    protected static string $resource = MonthlyStatementResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['generated_at'] = now();

        return $data;
    }
}
