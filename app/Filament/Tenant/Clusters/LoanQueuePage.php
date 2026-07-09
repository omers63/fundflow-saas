<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Pages\LoanQueueWorkbenchPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Legacy cluster redirect — queue now lives on {@see LoanQueueWorkbenchPage}.
 */
class LoanQueuePage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?string $slug = 'queue-nav';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.loan-queue-redirect';

    public function mount(): void
    {
        $this->redirect(LoanQueueWorkbenchPage::getUrl(), navigate: true);
    }
}
