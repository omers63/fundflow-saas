<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Support\LoanDelinquencyHeaderActions;
use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    protected function makeTable(): Table
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            $table = $this->makeBaseTable();

            static::getResource()::configureTable($table);

            return $table;
        }

        return parent::makeTable();
    }

    public function updatedActiveTab(): void
    {
        $this->tableSort = null;
        $this->reconfigureTableForActiveTab();

        parent::updatedActiveTab();
    }

    protected function reconfigureTableForActiveTab(): void
    {
        $this->table = $this->table($this->makeTable());

        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));

        $this->initTableColumnManager();

        $this->tableFilters = [];
        $this->getTableFiltersForm()->fill([]);
    }

    public function isTableColumnToggledHidden(string $name): bool
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return false;
        }

        return parent::isTableColumnToggledHidden($name);
    }

    public function getTableColumnsSessionKey(): string
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return 'tables.' . md5(static::class . '|arrears') . '_columns';
        }

        return parent::getTableColumnsSessionKey();
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return 'tables.' . md5(static::class . '|arrears') . '_has_reordered_columns';
        }

        return parent::getHasReorderedTableColumnsSessionKey();
    }

    public function getFilteredTableQuery(): ?Builder
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return null;
        }

        return parent::getFilteredTableQuery();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (ContributionResource::resolveListTab()) {
            'arrears' => __('Unposted contribution periods after the deadline (since each member joined). Post contributions from the member record or ledger tab.'),
            default => __('Track monthly contributions, posting status, open-cycle collection, and cycle workflows.'),
        };
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('contributionCycles')
                ->label(__('Contribution cycles'))
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->url(ContributionCyclePage::getUrl()),
            CreateAction::make()
                ->visible(fn(): bool => ContributionResource::resolveListTab() === 'ledger'),
            Action::make('generateMonthly')
                ->label(__('Generate pending'))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn(): bool => ContributionResource::resolveListTab() === 'ledger')
                ->modalDescription(fn(): string => __('Generate pending rows for the open cycle: :period', [
                    'period' => app(ContributionCycleService::class)->currentOpenPeriodLabel(),
                ]))
                ->action(function (ContributionService $service, Component $livewire) {
                    $count = $service->generateMonthlyContributions();
                    Notification::make()
                        ->title(__(':count contribution(s) generated', ['count' => $count]))
                        ->success()
                        ->send();

                    ContributionResource::dispatchInsightsRefresh($livewire);
                }),
        ];

        if (ContributionResource::resolveListTab() === 'arrears') {
            array_unshift(
                $actions,
                ActionGroup::make(LoanDelinquencyHeaderActions::make($this))
                    ->label(__('Delinquency tools'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('gray')
                    ->button(),
            );
        }

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ContributionInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        return [
            'ledger' => Tab::make(ContributionResource::listTabLabel('ledger')),
            'arrears' => Tab::make(ContributionResource::listTabLabel('arrears'))
                ->badge((string) ContributionResource::contributionArrearsPeriodCount())
                ->badgeColor('warning'),
        ];
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = ContributionResource::resolveListTab();

        return $tab === 'ledger' ? null : 'contributions-' . $tab;
    }
}
