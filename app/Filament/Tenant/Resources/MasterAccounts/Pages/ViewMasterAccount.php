<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Widgets\AccountDetailInsightsWidget;
use App\Models\Tenant\Setting;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

class ViewMasterAccount extends ViewRecord
{
    use RefreshesResourceRecord;

    protected static string $resource = MasterAccountResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
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
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'cash' => 'info',
                                'fund' => 'success',
                                'bank' => 'primary',
                                'expense' => 'danger',
                                'fees' => 'warning',
                                'invest' => 'gray',
                            }),
                        TextEntry::make('balance')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                    ]),
            ]);
    }
}
