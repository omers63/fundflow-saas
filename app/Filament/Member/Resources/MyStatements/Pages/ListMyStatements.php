<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyStatements\Pages;

use App\Filament\Member\Resources\MyStatements\MyStatementResource;
use App\Filament\Member\Widgets\MemberStatementsDownloadWidget;
use Filament\Resources\Pages\ListRecords;

class ListMyStatements extends ListRecords
{
    protected static string $resource = MyStatementResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MemberStatementsDownloadWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Download monthly summaries of contributions, repayments, and balances.');
    }
}
