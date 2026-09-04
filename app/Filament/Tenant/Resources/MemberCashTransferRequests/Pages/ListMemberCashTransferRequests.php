<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages;

use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Filament\Tenant\Widgets\MemberCashTransferRequestInsightsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListMemberCashTransferRequests extends ListRecords
{
    protected static string $resource = MemberCashTransferRequestResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderWidgets(): array
    {
        return [
            MemberCashTransferRequestInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
