<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportSessions\Pages;

use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Tenant\Resources\SmsImportSessions\SmsImportSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsImportSessions extends ListRecords
{
    protected static string $resource = SmsImportSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BankWorkspaceImportTableHeaderActions::smsImportAction(
                fn (): mixed => $this->resetTable(),
            ),
        ];
    }
}
