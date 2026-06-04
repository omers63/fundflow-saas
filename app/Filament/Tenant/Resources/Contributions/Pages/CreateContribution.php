<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Models\Tenant\Contribution;
use App\Support\ContributionCollectionStatus;
use Filament\Resources\Pages\CreateRecord;

class CreateContribution extends CreateRecord
{
    protected static string $resource = ContributionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['payment_method'] = Contribution::PAYMENT_METHOD_ADMIN;
        $data['status'] = 'pending';
        $data['collection_status'] = ContributionCollectionStatus::PENDING;
        $data['amount_due'] = $data['amount'] ?? 0;
        $data['amount_collected'] = 0;

        return $data;
    }
}
