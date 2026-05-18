<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Widgets\BankStatementDetailInsightsWidget;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

class ViewBankStatement extends ViewRecord
{
    use RefreshesResourceRecord;

    protected static string $resource = BankAccountsResource::class;

    public function getHeading(): string
    {
        return $this->record->filename;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            BankStatementDetailInsightsWidget::class,
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
            'bankStatementId' => $this->getRecord()->getKey(),
        ];
    }

    #[On('refresh-bank-statement-detail-insights')]
    public function refreshStatementFromImport(int $bankStatementId): void
    {
        if ((int) $this->getRecord()->getKey() !== $bankStatementId) {
            return;
        }

        $this->refreshResolvedRecord();
    }

    public function schema(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('Statement Details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('filename'),
                    TextEntry::make('bank_name')
                        ->placeholder(__('—')),
                    TextEntry::make('statement_date')
                        ->date(),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'processing' => 'info',
                            'completed' => 'success',
                            'failed' => 'danger',
                        }),
                    TextEntry::make('total_rows'),
                    TextEntry::make('imported_rows'),
                    TextEntry::make('duplicate_rows'),
                    TextEntry::make('imported_at')
                        ->dateTime(),
                    TextEntry::make('notes')
                        ->placeholder(__('No notes'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
