<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundAuditLogs\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\FundAuditLogs\FundAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListFundAuditLogs extends ListRecords
{
    use TranslatesPageNavigationLabel;

    protected static string $resource = FundAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
