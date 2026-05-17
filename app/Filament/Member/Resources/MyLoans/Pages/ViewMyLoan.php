<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyLoans\Widgets\MyLoanViewInsights;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMyLoan extends ViewRecord
{
    protected static string $resource = MyLoanResource::class;

    public function getHeading(): string
    {
        return __('Loan #:id', ['id' => $this->record->getKey()]);
    }

    public function getSubheading(): ?string
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return number_format((float) $this->record->amount_requested, 2).' '.$currency;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MyLoanViewInsights::class,
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
            'record' => $this->getRecord(),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema->schema([
            Section::make(__('Loan summary'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextEntry::make('amount_requested')
                        ->label(__('Requested'))
                        ->money($currency),
                    TextEntry::make('amount_approved')
                        ->label(__('Approved'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextEntry::make('amount_disbursed')
                        ->label(__('Disbursed'))
                        ->money($currency),
                    TextEntry::make('outstanding')
                        ->label(__('Outstanding'))
                        ->state(fn (Loan $record): float => $record->getOutstandingBalance())
                        ->money($currency),
                    TextEntry::make('installments_count')
                        ->label(__('Installments'))
                        ->placeholder(__('—')),
                    TextEntry::make('guarantor.name')
                        ->label(__('Guarantor'))
                        ->placeholder(__('—')),
                    TextEntry::make('has_grace_cycle')
                        ->label(__('Grace cycle'))
                        ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
                    TextEntry::make('purpose')
                        ->placeholder(__('—'))
                        ->columnSpanFull(),
                    TextEntry::make('rejection_reason')
                        ->label(__('Rejection reason'))
                        ->visible(fn (Loan $record): bool => $record->status === 'rejected')
                        ->columnSpanFull(),
                    TextEntry::make('applied_at')
                        ->dateTime(),
                    TextEntry::make('approved_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('disbursed_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('payout_at')
                        ->label(__('Bank payout'))
                        ->dateTime()
                        ->placeholder(__('—')),
                    TextEntry::make('settled_at')
                        ->dateTime()
                        ->placeholder(__('—')),
                ]),
        ]);
    }
}
