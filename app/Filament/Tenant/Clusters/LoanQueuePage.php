<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Sub-navigation entry for the loan queue (list lives on the loan resource).
 */
class LoanQueuePage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'queue-nav';

    protected string $view = 'filament.tenant.pages.loan-queue-redirect';

    public static function getNavigationUrl(): string
    {
        return LoanResource::getUrl('queue');
    }

    /**
     * Queue list is registered on {@see LoanResource}, not this redirect page.
     *
     * @return string|array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return LoanResource::getRouteBaseName().'.queue';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Loan::query()->inQueue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->redirect(LoanResource::getUrl('queue'), navigate: true);
    }
}
