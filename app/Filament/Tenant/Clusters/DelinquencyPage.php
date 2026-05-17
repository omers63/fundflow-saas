<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\LoanInstallment;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sub-navigation entry for the delinquency workspace.
 */
class DelinquencyPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Delinquency';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'delinquency-nav';

    protected string $view = 'filament.tenant.pages.loan-queue-redirect';

    public static function getNavigationUrl(): string
    {
        return LoanResource::getUrl('delinquency');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn (Builder $q): Builder => $q->where('status', 'active'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $tab = request()->query('tab');
        $url = LoanResource::getUrl('delinquency');

        if (is_string($tab) && $tab !== '') {
            $url .= '?tab='.$tab;
        }

        $this->redirect($url, navigate: true);
    }
}
