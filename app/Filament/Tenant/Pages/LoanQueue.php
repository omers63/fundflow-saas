<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Navigation entry that redirects to the queue list on the loan resource.
 */
class LoanQueue extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?string $slug = 'loan-queue';

    protected static ?int $navigationSort = 3;

    protected static string|\UnitEnum|null $navigationGroup = 'Loans';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.tenant.pages.loan-queue-redirect';

    public function mount(): void
    {
        $this->redirect(LoanResource::getUrl('queue'), navigate: true);
    }
}
