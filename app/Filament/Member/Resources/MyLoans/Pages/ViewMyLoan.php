<?php

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Setting;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TableEntry;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ViewMyLoan extends ViewRecord
{
    protected static string $resource = MyLoanResource::class;

    public function getHeading(): string
    {
        return 'Loan — $'.number_format((float) $this->record->amount, 2);
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Loan Details'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Loan amount')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                        TextEntry::make('interest_rate')
                            ->suffix('%'),
                        TextEntry::make('term_months')
                            ->label('Term')
                            ->suffix(' months'),
                        TextEntry::make('monthly_repayment')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                        TextEntry::make('total_repaid')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                        TextEntry::make('status')
                            ->badge(),
                    ]),
                Section::make(__('Timeline'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('applied_at')
                            ->dateTime(),
                        TextEntry::make('approved_at')
                            ->dateTime()
                            ->placeholder(__('—')),
                        TextEntry::make('disbursed_at')
                            ->dateTime()
                            ->placeholder(__('—')),
                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('Repayment History'))
                    ->schema([
                        TableEntry::make('repayments')
                            ->table(
                                fn (Table $table): Table => $table
                                    ->columns([
                                        TextColumn::make('paid_at')
                                            ->dateTime()
                                            ->sortable(),
                                        TextColumn::make('amount')
                                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                                        TextColumn::make('description')
                                            ->limit(50),
                                    ])
                                    ->defaultSort('paid_at', 'desc')
                            ),
                    ]),
            ]);
    }
}
