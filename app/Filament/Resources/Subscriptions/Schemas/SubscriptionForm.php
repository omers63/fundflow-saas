<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subscription Details')
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
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'pending' => 'Pending',
                                'cancelled' => 'Cancelled',
                                'expired' => 'Expired',
                                'pending_upgrade' => 'Pending Upgrade',
                                'pending_extension' => 'Pending Extension',
                            ])
                            ->required()
                            ->default('active'),
                    ]),

                Section::make('Timeline')
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('starts_at'),
                        DateTimePicker::make('ends_at'),
                        DateTimePicker::make('trial_ends_at'),
                        DateTimePicker::make('cancels_at'),
                        DateTimePicker::make('canceled_at'),
                    ]),
            ]);
    }
}
