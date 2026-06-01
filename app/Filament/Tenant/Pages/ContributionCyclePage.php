<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Legacy route — redirects to Contributions → To collect tab.
 */
class ContributionCyclePage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Contribution cycles';

    protected static string|UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'contribution-cycle';

    protected string $view = 'filament.tenant.pages.contribution-cycle-redirect';

    public function mount(): void
    {
        $tab = request()->query('tab');

        $url = match ($tab) {
            'paid', 'collected' => ContributionResource::listTabUrl('collected'),
            'pending', 'collect' => ContributionResource::listTabUrl('collect'),
            default => ContributionResource::listTabUrl('collect'),
        };

        $this->redirect($url, navigate: true);
    }
}
