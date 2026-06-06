<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use Filament\Resources\Pages\ListRecords;

class ListReconciliationExceptions extends ListRecords
{
    use TranslatesPageNavigationLabel;

    protected static string $resource = ReconciliationExceptionResource::class;

    public function mount(): void
    {
        redirect(ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']));
    }
}
