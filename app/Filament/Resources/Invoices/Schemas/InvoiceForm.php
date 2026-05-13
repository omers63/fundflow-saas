<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Header')
                    ->columns(2)
                    ->schema([
                        TextInput::make('invoice_number')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'paid' => 'Paid',
                                'failed' => 'Failed',
                                'cancelled' => 'Cancelled',
                            ])
                            ->required()
                            ->default('pending'),
                    ]),

                Section::make('Billing Information')
                    ->columns(2)
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Tenant')
                            ->options(Tenant::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->options(Plan::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->default(0.0),
                        TextInput::make('currency')
                            ->required()
                            ->default('IDR'),
                    ]),

                Section::make('Payment Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('payment_method'),
                        DateTimePicker::make('paid_at'),
                        TextInput::make('description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
