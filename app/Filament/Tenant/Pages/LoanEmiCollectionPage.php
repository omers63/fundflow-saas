<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\LoanEmiCollectionTables;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class LoanEmiCollectionPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static ?string $cluster = LoansCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'EMI collection';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'emi-collection';

    protected static ?string $title = 'EMI collection';

    protected string $view = 'filament.tenant.pages.loan-emi-collection';

    public string $emiTab = 'collect';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public function mount(): void
    {
        $tab = request()->query('tab');

        if (is_string($tab) && in_array($tab, ['collect', 'collected'], true)) {
            $this->emiTab = $tab;
        }
    }

    public function setEmiTab(string $tab): void
    {
        if (! in_array($tab, ['collect', 'collected'], true)) {
            return;
        }

        if ($this->emiTab === $tab) {
            return;
        }

        $this->emiTab = $tab;
        $this->resetTable();
    }

    public function getTitle(): string|Htmlable
    {
        return __('EMI collection');
    }

    public function getSubheading(): ?string
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $period = $catalog->periodLabel($month, $year);

        return match ($this->emiTab) {
            'collected' => __('Installments paid from member cash for EMIs due through :period.', [
                'period' => $period,
            ]),
            default => __('Members with pending EMIs through the open period (:period). Apply from cash balance (open period and arrears only).', [
                'period' => $period,
            ]),
        };
    }

    public static function getNavigationBadge(): ?string
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $count = $catalog->pendingMemberCount($month, $year);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function url(string $tab = 'collect'): string
    {
        $parameters = $tab !== 'collect' ? ['tab' => $tab] : [];

        return static::getUrl($parameters);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('all_loans')
                ->label(__('All loans'))
                ->icon('heroicon-o-document-text')
                ->url(LoanResource::getUrl('index')),
        ];
    }

    public function table(Table $table): Table
    {
        return match ($this->emiTab) {
            'collected' => LoanEmiCollectionTables::configureCollectedTable($table),
            default => LoanEmiCollectionTables::configurePendingMembersTable($table),
        };
    }
}
