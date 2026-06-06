<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SupportRequests\Pages;

use App\Filament\Tenant\Resources\SupportRequests\SupportRequestResource;
use App\Models\Tenant\SupportRequest;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSupportRequests extends ListRecords
{
    protected static string $resource = SupportRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Messages submitted by members from Support & requests in the member portal. Stored permanently; not tied to notification history.');
    }

    protected function applyFiltersToTableQuery(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if ($query->getModel() === null) {
            $query = SupportRequest::query();
        }

        return parent::applyFiltersToTableQuery($query, $isResolvingRecord);
    }

    public function filterTableQuery(Builder $query): Builder
    {
        if ($query->getModel() === null) {
            $query = SupportRequest::query();
        }

        return parent::filterTableQuery($query);
    }

    public function getTableQueryForExport(): Builder
    {
        $query = $this->getTable()->getQuery();

        if ($query->getModel() === null) {
            $query = SupportRequest::query();
        }

        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);
        $this->applySortingToTableQuery($query);

        return $query;
    }
}
