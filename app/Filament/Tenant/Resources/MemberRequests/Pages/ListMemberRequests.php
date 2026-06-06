<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Pages;

use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Models\Tenant\MemberRequest;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMemberRequests extends ListRecords
{
    protected static string $resource = MemberRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Review allocation and family changes submitted by members.');
    }

    protected function applyFiltersToTableQuery(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        return parent::applyFiltersToTableQuery($query, $isResolvingRecord);
    }

    public function filterTableQuery(Builder $query): Builder
    {
        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        return parent::filterTableQuery($query);
    }

    public function getTableQueryForExport(): Builder
    {
        $query = $this->getTable()->getQuery();

        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);
        $this->applySortingToTableQuery($query);

        return $query;
    }
}
