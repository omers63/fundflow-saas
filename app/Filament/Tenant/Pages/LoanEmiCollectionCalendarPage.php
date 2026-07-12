<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Clusters\LoansCluster;

/**
 * @deprecated Use {@see CollectionCalendarPage}. Kept for legacy cluster URLs.
 */
class LoanEmiCollectionCalendarPage extends CollectionCalendarPage
{
    protected static ?string $cluster = LoansCluster::class;

    protected static ?string $slug = 'emi-collection-calendar';

    protected string $view = 'filament.tenant.pages.collection-calendar';

    public function mount(): void
    {
        $this->redirect(CollectionCalendarPage::getUrl(), navigate: true);
    }
}
