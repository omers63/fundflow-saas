<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Pages;

use App\Filament\Concerns\FocusesLedgerTransactionOnViewRecord;
use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Widgets\AccountDetailInsightsWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

class ViewMasterAccount extends ViewRecord
{
    use FocusesLedgerTransactionOnViewRecord;
    use RefreshesResourceRecord;

    protected static string $resource = MasterAccountResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->bootstrapFocusedLedgerTransaction(TransactionsRelationManager::class);
    }

    public function getHeading(): string
    {
        return $this->record->displayLabel();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountDetailInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'accountId' => $this->getRecord()->getKey(),
        ];
    }

    #[On('refresh-account-detail-insights')]
    public function refreshAccountFromLedger(int $accountId): void
    {
        if ((int) $this->getRecord()->getKey() !== $accountId) {
            return;
        }

        $this->refreshResolvedRecord();
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account Details'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->formatStateUsing(fn (string $state, Account $record): string => $record->displayLabel()),
                        TextEntry::make('type')
                            ->formatStateUsing(fn (string $state): string => MasterAccountResource::tabLabel($state))
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'cash' => 'info',
                                'fund' => 'success',
                                'bank' => 'primary',
                                'expense' => 'danger',
                                'fees' => 'warning',
                                'invest' => 'gray',
                                'suspense' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('balance')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                    ]),
            ]);
    }
}
